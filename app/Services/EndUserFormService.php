<?php

namespace App\Services;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Entry;
use App\Models\Stage;
use App\Models\EntryValue;
use App\Models\User;
use App\Models\Language;
use App\Models\StageTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EndUserFormService
{
    protected $accessCheckService;
    protected $fieldValidationService;
    protected $fieldValueHandlerService;
    protected $actionExecutionService;

    public function __construct(
        StageAccessCheckService $accessCheckService,
        FieldValidationService $fieldValidationService,
        FieldValueHandlerService $fieldValueHandlerService,
        ActionExecutionService $actionExecutionService
    ) {
        $this->accessCheckService = $accessCheckService;
        $this->fieldValidationService = $fieldValidationService;
        $this->fieldValueHandlerService = $fieldValueHandlerService;
        $this->actionExecutionService = $actionExecutionService;
    }

    /**
     * Get list of available forms for end user based on their access
     */
    public function getAvailableFormsForUser(?int $userId, ?int $languageId = null): array
    {
        $user = $userId ? User::find($userId) : null;

        // Determine language to use
        if (!$languageId) {
            $languageId = $user?->default_language_id ?? Language::where('is_default', true)->value('id');
        }

        // Get accessible form IDs based on stage access rules
        $accessibleFormIds = $this->accessCheckService->getAccessibleFormIds($user);

        // Get published forms that are not archived and user has access to
        $forms = Form::whereIn('id', $accessibleFormIds)
            ->where('is_archived', false)
            ->with(['formVersions' => function($query) {
                $query->where('status', 'published')
                      ->orderBy('version_number', 'desc')
                      ->limit(1);
            }])
            ->get();

        $result = [];
        foreach ($forms as $form) {
            $version = $form->formVersions->first();
            if (!$version) continue;

            // Get translation if exists
            $translation = $version->translations()
                ->where('language_id', $languageId)
                ->first();

            $result[] = [
                'form_id' => $form->id,
                'form_version_id' => $version->id,
                'name' => $translation ? $translation->name : $form->name,
                'version_number' => $version->version_number,
            ];
        }

        return $result;
    }

    /**
     * Get form structure for initial submission
     */
    public function getFormStructure(int $formVersionId, ?int $userId = null, ?int $languageId = null): array
    {
        $user = $userId ? User::find($userId) : null;

        // Determine language
        if (!$languageId) {
            $languageId = $user?->default_language_id ?? Language::where('is_default', true)->value('id');
        }

        $formVersion = FormVersion::with([
            'form',
            'stages' => function($query) {
                $query->where('is_initial', true)
                      ->with([
                          'accessRule',
                          'sections.fields.fieldType',
                          'sections.fields.rules.inputRule',
                          'sections.fields.translations' => function($q) use ($languageId) {
                              $q->where('language_id', $languageId);
                          }
                      ]);
            },
            'translations' => function($q) use ($languageId) {
                $q->where('language_id', $languageId);
            }
        ])->findOrFail($formVersionId);

        $initialStage = $formVersion->stages->first();

        if (!$initialStage) {
            throw new \Exception('No initial stage found for this form version.');
        }

        // Check access to initial stage
        if (!$this->accessCheckService->canUserAccessStage($initialStage, $user)) {
            throw new \Exception('You do not have access to this form.');
        }

        // Get form translation
        $formTranslation = $formVersion->translations->first();
        $formName = $formTranslation ? $formTranslation->name : $formVersion->form->name;

        // Build stage structure with visibility evaluation
        $stageData = $this->buildStageStructure($initialStage, [], $languageId);

        return [
            'form_version_id' => $formVersion->id,
            'form_name' => $formName,
            'stage' => $stageData,
        ];
    }

    /**
     * Submit initial stage of a form
     */
    public function submitInitialStage(int $formVersionId, array $fieldValues, ?int $userId = null): array
    {
        $user = $userId ? User::find($userId) : null;

        DB::beginTransaction();
        try {
            $formVersion = FormVersion::with([
                'stages' => function($query) {
                    $query->where('is_initial', true)->with('accessRule', 'sections.fields.fieldType');
                }
            ])->findOrFail($formVersionId);

            $initialStage = $formVersion->stages->first();

            if (!$initialStage) {
                throw new \Exception('No initial stage found.');
            }

            // Check access
            if (!$this->accessCheckService->canUserAccessStage($initialStage, $user)) {
                throw new \Exception('You do not have access to submit this form.');
            }

            // Validate submission
            $errors = $this->fieldValidationService->validateSubmissionValues(
                $fieldValues,
                $initialStage->id,
                $fieldValues
            );

            if (!empty($errors)) {
                throw new \Exception('Validation failed: ' . json_encode($errors));
            }

            // Create entry
            $entry = Entry::create([
                'form_version_id' => $formVersion->id,
                'current_stage_id' => $initialStage->id,
                'public_identifier' => (string) Str::uuid(),
                'is_complete' => false,
                'is_considered' => false,
                'created_by_user_id' => $userId,
            ]);

            // Save field values
            foreach ($fieldValues as $fieldId => $value) {
                $field = \App\Models\Field::find($fieldId);
                if (!$field) continue;

                $processedValue = $this->fieldValueHandlerService->processFieldValue(
                    $value,
                    $field->fieldType->name
                );

                EntryValue::create([
                    'entry_id' => $entry->id,
                    'field_id' => $fieldId,
                    'value' => $processedValue,
                ]);
            }

            // Find available transitions from initial stage
            $transitions = StageTransition::where('form_version_id', $formVersion->id)
                ->where('from_stage_id', $initialStage->id)
                ->with('actions.action')
                ->get();

            // For now, we stay at initial stage after submission
            // Transitions will be handled when buttons are clicked

            DB::commit();

            return [
                'success' => true,
                'entry_id' => $entry->id,
                'public_identifier' => $entry->public_identifier,
                'message' => 'Form submitted successfully',
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get entry by public identifier for later stage filling
     */
    public function getEntryByPublicIdentifier(string $publicIdentifier, ?int $userId = null, ?int $languageId = null): array
    {
        $user = $userId ? User::find($userId) : null;

        // Determine language
        if (!$languageId) {
            $languageId = $user?->default_language_id ?? Language::where('is_default', true)->value('id');
        }

        $entry = Entry::with([
            'formVersion.form',
            'formVersion.translations' => function($q) use ($languageId) {
                $q->where('language_id', $languageId);
            },
            'formVersion.stages.sections.fields.fieldType',
            'formVersion.stages.sections.fields.rules.inputRule',
            'formVersion.stages.sections.fields.translations' => function($q) use ($languageId) {
                $q->where('language_id', $languageId);
            },
            'formVersion.stages.accessRule',
            'currentStage',
            'values.field'
        ])->where('public_identifier', $publicIdentifier)
          ->firstOrFail();

        // Check if user can access the current stage
        if (!$this->accessCheckService->canUserAccessEntry($entry, $user)) {
            throw new \Exception('You do not have access to this entry at its current stage.');
        }

        // Get form translation
        $formTranslation = $entry->formVersion->translations->first();
        $formName = $formTranslation ? $formTranslation->name : $entry->formVersion->form->name;

        // Build all stages data (previous stages read-only, current stage editable)
        $stagesData = [];
        $allStages = $entry->formVersion->stages()->orderBy('id')->get();
        $existingValues = $this->getExistingValuesMap($entry);

        foreach ($allStages as $stage) {
            $isCurrentStage = $stage->id === $entry->current_stage_id;
            $isPreviousStage = $stage->id < $entry->current_stage_id;

            if ($isCurrentStage || $isPreviousStage) {
                $stagesData[] = [
                    'stage_id' => $stage->id,
                    'stage_name' => $stage->name,
                    'is_current' => $isCurrentStage,
                    'is_readonly' => $isPreviousStage,
                    'structure' => $this->buildStageStructure($stage, $existingValues, $languageId),
                ];
            }
        }

        return [
            'entry_id' => $entry->id,
            'public_identifier' => $entry->public_identifier,
            'form_name' => $formName,
            'is_complete' => $entry->is_complete,
            'current_stage_id' => $entry->current_stage_id,
            'stages' => $stagesData,
        ];
    }

    /**
     * Submit later stage of an entry
     */
    public function submitLaterStage(string $publicIdentifier, array $fieldValues, int $transitionId, ?int $userId = null): array
    {
        $user = $userId ? User::find($userId) : null;

        DB::beginTransaction();
        try {
            $entry = Entry::with([
                'currentStage.accessRule',
                'formVersion.stages.sections.fields.fieldType'
            ])->where('public_identifier', $publicIdentifier)
              ->firstOrFail();

            // Check if entry is already complete
            if ($entry->is_complete) {
                throw new \Exception('This entry is already complete.');
            }

            // Check access to current stage
            if (!$this->accessCheckService->canUserAccessEntry($entry, $user)) {
                throw new \Exception('You do not have access to submit this stage.');
            }

            // Validate the transition exists and is valid
            $transition = StageTransition::with('actions.action')
                ->where('id', $transitionId)
                ->where('form_version_id', $entry->form_version_id)
                ->where('from_stage_id', $entry->current_stage_id)
                ->firstOrFail();

            // Get all existing values for validation context
            $allValues = $this->getExistingValuesMap($entry);
            $allValues = array_merge($allValues, $fieldValues);

            // Validate new submission
            $errors = $this->fieldValidationService->validateSubmissionValues(
                $fieldValues,
                $entry->current_stage_id,
                $allValues
            );

            if (!empty($errors)) {
                throw new \Exception('Validation failed: ' . json_encode($errors));
            }

            // Save new field values
            foreach ($fieldValues as $fieldId => $value) {
                $field = \App\Models\Field::find($fieldId);
                if (!$field) continue;

                $processedValue = $this->fieldValueHandlerService->processFieldValue(
                    $value,
                    $field->fieldType->name
                );

                EntryValue::updateOrCreate(
                    [
                        'entry_id' => $entry->id,
                        'field_id' => $fieldId,
                    ],
                    [
                        'value' => $processedValue,
                    ]
                );
            }

            // Execute transition actions
            $this->actionExecutionService->executeTransitionActions($transition, $entry, $user);

            // Update entry stage or mark as complete
            if ($transition->to_complete) {
                $entry->update([
                    'is_complete' => true,
                ]);
                $message = 'Entry completed successfully';
            } elseif ($transition->to_stage_id) {
                $nextStage = Stage::findOrFail($transition->to_stage_id);

                $entry->update([
                    'current_stage_id' => $nextStage->id,
                ]);
                $message = 'Entry moved to next stage: ' . $nextStage->name;
            }

            DB::commit();

            return [
                'success' => true,
                'entry_id' => $entry->id,
                'public_identifier' => $entry->public_identifier,
                'is_complete' => $entry->is_complete,
                'current_stage_id' => $entry->current_stage_id,
                'message' => $message,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Build stage structure with sections and fields
     */
    private function buildStageStructure(Stage $stage, array $existingValues, int $languageId): array
    {
        $sections = [];

        foreach ($stage->sections as $section) {
            // Check section visibility
            if (!$this->isSectionVisible($section, $existingValues)) {
                continue;
            }

            $fields = [];
            foreach ($section->fields as $field) {
                // Check field visibility
                if (!$this->isFieldVisible($field, $existingValues)) {
                    continue;
                }

                // Get field translation
                $fieldTranslation = $field->translations->first();

                $fields[] = [
                    'field_id' => $field->id,
                    'field_type' => $field->fieldType->name,
                    'label' => $fieldTranslation ? $fieldTranslation->label : $field->label,
                    'placeholder' => $field->placeholder,
                    'helper_text' => $fieldTranslation ? $fieldTranslation->helper_text : $field->helper_text,
                    'default_value' => $fieldTranslation ? $fieldTranslation->default_value : $field->default_value,
                    'current_value' => $existingValues[$field->id] ?? null,
                    'rules' => $field->rules->map(function($rule) {
                        return [
                            'rule_name' => $rule->inputRule->name,
                            'rule_props' => $rule->rule_props ? json_decode($rule->rule_props, true) : null,
                        ];
                    })->toArray(),
                ];
            }

            if (!empty($fields)) {
                $sections[] = [
                    'section_id' => $section->id,
                    'section_name' => $section->name,
                    'fields' => $fields,
                ];
            }
        }

        return [
            'stage_id' => $stage->id,
            'stage_name' => $stage->name,
            'sections' => $sections,
        ];
    }

    /**
     * Check if section is visible based on conditions
     */
    private function isSectionVisible($section, array $values): bool
    {
        if (empty($section->visibility_condition)) {
            return true;
        }

        $condition = json_decode($section->visibility_condition, true);
        return $this->fieldValidationService->evaluateCondition($condition, $values);
    }

    /**
     * Check if field is visible based on conditions
     */
    private function isFieldVisible($field, array $values): bool
    {
        if (empty($field->visibility_condition)) {
            return true;
        }

        $condition = json_decode($field->visibility_condition, true);
        return $this->fieldValidationService->evaluateCondition($condition, $values);
    }

    /**
     * Get existing values as fieldId => value map
     */
    private function getExistingValuesMap(Entry $entry): array
    {
        $values = [];
        foreach ($entry->values as $entryValue) {
            $values[$entryValue->field_id] = $entryValue->value;
        }
        return $values;
    }
}
