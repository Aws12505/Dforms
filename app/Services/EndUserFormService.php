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
use App\Models\Field;
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

    // Determine language before using it in closures
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
                  ]);
        },
        'translations' => function($q) use ($languageId) {
            $q->where('language_id', $languageId);
        },
        'stageTransitions.toStage', // Load transitions
        'stageTransitions.actions.action' // Load transition actions
    ])->findOrFail($formVersionId);

    // Load field translations separately to avoid closure issues
    $formVersion->load(['stages.sections.fields.translations' => function($q) use ($languageId) {
        $q->where('language_id', $languageId);
    }]);

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
    $stageData = $this->buildStageStructureWithDetails($initialStage, [], $languageId);

    // Get available transitions from initial stage
    $availableTransitions = $formVersion->stageTransitions()
        ->where('from_stage_id', $initialStage->id)
        ->with(['toStage', 'actions.action'])
        ->get()
        ->map(function($transition) {
            return [
                'transition_id' => $transition->id,
                'label' => $transition->label,
                'to_stage_id' => $transition->to_stage_id,
                'to_stage_name' => $transition->toStage ? $transition->toStage->name : null,
                'to_complete' => $transition->to_complete,
                'condition' => $transition->condition ? (is_string($transition->condition) ? json_decode($transition->condition, true) : $transition->condition) : null,
                'actions' => $transition->actions->map(function($action) {
                    return [
                        'action_id' => $action->action_id,
                        'action_name' => $action->action->name,
                    ];
                })->toArray(),
            ];
        })->toArray();

    return [
        'form_version_id' => $formVersion->id,
        'form_name' => $formName,
        'version_number' => $formVersion->version_number,
        'stage' => $stageData,
        'available_transitions' => $availableTransitions,
    ];
}

/**
 * Build stage structure with complete details
 */
private function buildStageStructureWithDetails(Stage $stage, array $existingValues, int $languageId): array
{
    // Load relationships if not loaded
    if (!$stage->relationLoaded('sections')) {
        $stage->load('sections.fields.fieldType', 'sections.fields.rules.inputRule', 'accessRule');
    }

    $sections = [];

    foreach ($stage->sections as $section) {
        // Handle visibility_condition as either array or string
        $visibilityCondition = $section->visibility_condition;
        if (is_string($visibilityCondition)) {
            $visibilityCondition = json_decode($visibilityCondition, true);
        }

        // Check section visibility
        if (!$this->isSectionVisible($visibilityCondition, $existingValues)) {
            continue;
        }

        $fields = [];
        foreach ($section->fields as $field) {
            // Load field relationships
            if (!$field->relationLoaded('translations')) {
                $field->load(['translations' => function($q) use ($languageId) {
                    $q->where('language_id', $languageId);
                }]);
            }

            // Handle visibility_condition as either array or string
            $fieldVisibilityCondition = $field->visibility_condition;
            if (is_string($fieldVisibilityCondition)) {
                $fieldVisibilityCondition = json_decode($fieldVisibilityCondition, true);
            }

            // Check field visibility
            if (!$this->isFieldVisible($fieldVisibilityCondition, $existingValues)) {
                continue;
            }

            // Get field translation
            $fieldTranslation = $field->translations->first();

            // Parse default value if it's JSON
            $defaultValue = $fieldTranslation ? $fieldTranslation->default_value : $field->default_value;
            if (is_string($defaultValue) && json_decode($defaultValue) !== null) {
                $defaultValue = json_decode($defaultValue, true);
            }

            $fields[] = [
                'field_id' => $field->id,
                'field_type_id' => $field->field_type_id,
                'field_type' => $field->fieldType->name,
                'label' => $fieldTranslation ? $fieldTranslation->label : $field->label,
                'placeholder' => $field->placeholder,
                'helper_text' => $fieldTranslation ? $fieldTranslation->helper_text : $field->helper_text,
                'default_value' => $defaultValue,
                'current_value' => $existingValues[$field->id] ?? null,
                'visibility_condition' => $fieldVisibilityCondition,
                'rules' => $field->rules->map(function($rule) {
                    $ruleProps = $rule->rule_props;
                    if (is_string($ruleProps) && json_decode($ruleProps) !== null) {
                        $ruleProps = json_decode($ruleProps, true);
                    }
                    
                    return [
                        'rule_id' => $rule->id,
                        'input_rule_id' => $rule->input_rule_id,
                        'rule_name' => $rule->inputRule->name,
                        'rule_description' => $rule->inputRule->description,
                        'rule_props' => $ruleProps,
                        'rule_condition' => $rule->rule_condition ? (is_string($rule->rule_condition) ? json_decode($rule->rule_condition, true) : $rule->rule_condition) : null,
                    ];
                })->toArray(),
            ];
        }

        if (!empty($fields)) {
            $sections[] = [
                'section_id' => $section->id,
                'section_name' => $section->name,
                'section_order' => $section->order,
                'visibility_condition' => $visibilityCondition,
                'fields' => $fields,
            ];
        }
    }

    // Build access rule details
    $accessRuleDetails = null;
    if ($stage->accessRule) {
        $accessRuleDetails = [
            'allow_authenticated_users' => $stage->accessRule->allow_authenticated_users,
            'allowed_users' => $stage->accessRule->allowed_users ? (is_string($stage->accessRule->allowed_users) ? json_decode($stage->accessRule->allowed_users, true) : $stage->accessRule->allowed_users) : null,
            'allowed_roles' => $stage->accessRule->allowed_roles ? (is_string($stage->accessRule->allowed_roles) ? json_decode($stage->accessRule->allowed_roles, true) : $stage->accessRule->allowed_roles) : null,
            'allowed_permissions' => $stage->accessRule->allowed_permissions ? (is_string($stage->accessRule->allowed_permissions) ? json_decode($stage->accessRule->allowed_permissions, true) : $stage->accessRule->allowed_permissions) : null,
            'email_field_id' => $stage->accessRule->email_field_id,
        ];
    }

    // Handle stage visibility_condition
    $stageVisibilityCondition = $stage->visibility_condition;
    if (is_string($stageVisibilityCondition)) {
        $stageVisibilityCondition = json_decode($stageVisibilityCondition, true);
    }

    return [
        'stage_id' => $stage->id,
        'stage_name' => $stage->name,
        'is_initial' => $stage->is_initial,
        'visibility_condition' => $stageVisibilityCondition,
        'access_rule' => $accessRuleDetails,
        'sections' => $sections,
    ];
}

    /**
     * Submit initial stage of a form
     */
    public function submitInitialStage(int $formVersionId, array $fieldValues, ?int $transitionId = null, ?int $userId = null): array
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
            $field = Field::find($fieldId);
            if (!$field) continue;

            $field->load('fieldType');

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

        // FIXED: Handle transition if provided
        if ($transitionId) {
            $transition = StageTransition::with('actions.action')
                ->where('id', $transitionId)
                ->where('form_version_id', $formVersion->id)
                ->where('from_stage_id', $initialStage->id)
                ->firstOrFail();

            // Execute transition actions
            $this->actionExecutionService->executeTransitionActions($transition->id, $entry);

            // Update entry based on transition
            if ($transition->to_complete) {
                $entry->update(['is_complete' => true]);
            } elseif ($transition->to_stage_id) {
                $entry->update(['current_stage_id' => $transition->to_stage_id]);
            }
        }

        DB::commit();

        return [
            'success' => true,
            'entry_id' => $entry->id,
            'public_identifier' => $entry->public_identifier,
            'is_complete' => $entry->is_complete,
            'current_stage_id' => $entry->current_stage_id,
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

        // FIXED: Determine language before using it
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
            'formVersion.stages.accessRule',
            'currentStage',
            'values.field'
        ])->where('public_identifier', $publicIdentifier)
          ->firstOrFail();

        // Load field translations separately
        $entry->load(['formVersion.stages.sections.fields.translations' => function($q) use ($languageId) {
            $q->where('language_id', $languageId);
        }]);

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
                    'structure' => $this->buildStageStructureWithDetails($stage, $existingValues, $languageId),
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
                $field = Field::with('fieldType')->find($fieldId);
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

            // FIXED: Execute transition actions with correct parameters
            $this->actionExecutionService->executeTransitionActions($transition->id, $entry);

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
            } else {
                $message = 'Entry updated successfully';
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
     * Check if section is visible based on conditions
     * FIXED: Accept either array or null
     */
    private function isSectionVisible($visibilityCondition, array $values): bool
    {
        if (empty($visibilityCondition)) {
            return true;
        }

        return $this->fieldValidationService->evaluateCondition($visibilityCondition, $values);
    }

    /**
     * Check if field is visible based on conditions
     * FIXED: Accept either array or null
     */
    private function isFieldVisible($visibilityCondition, array $values): bool
    {
        if (empty($visibilityCondition)) {
            return true;
        }

        return $this->fieldValidationService->evaluateCondition($visibilityCondition, $values);
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