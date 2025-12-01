<?php

namespace App\Services;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Stage;
use App\Models\Section;
use App\Models\Field;
use App\Models\FieldRule;
use App\Models\StageTransition;
use App\Models\StageTransitionAction;
use App\Models\StageAccessRule;
use Illuminate\Support\Facades\DB;

class FormVersionService
{
    /**
     * Create a new version - either blank or copied from current
     */
    public function createNewVersion(int $formId, bool $copyFromCurrent)
    {
        DB::beginTransaction();

        try {
            $form = Form::findOrFail($formId);

            // Get current/latest version
            $currentVersion = FormVersion::where('form_id', $formId)
                ->orderBy('version_number', 'desc')
                ->first();

            if (!$currentVersion) {
                throw new \Exception('No current version found to create new version from.');
            }

            // Calculate new version number
            $newVersionNumber = $currentVersion->version_number + 1;

            // Create new version
            $newVersion = FormVersion::create([
                'form_id' => $formId,
                'version_number' => $newVersionNumber,
                'status' => 'draft',
                'published_at' => null,
            ]);

            if ($copyFromCurrent) {
                // COPY from current version - including all relationships
                $this->copyVersionData($currentVersion, $newVersion);
            } else {
                // BLANK version: Create initial stage with one section only
                $initialStage = Stage::create([
                    'form_version_id' => $newVersion->id,
                    'name' => 'initial stage',
                    'is_initial' => true,
                    'visibility_condition' => null,
                ]);

                Section::create([
                    'stage_id' => $initialStage->id,
                    'name' => 'Section 1',
                    'order' => 0,
                    'visibility_conditions' => null,
                ]);
            }

            DB::commit();

            return $newVersion->load([
                'stages.sections.fields.rules.inputRule',
                'stages.accessRule',
                'stageTransitions.fromStage',
                'stageTransitions.toStage',
                'stageTransitions.actions.action'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Copy all data from one version to another
     */
    private function copyVersionData(FormVersion $sourceVersion, FormVersion $targetVersion): void
    {
        // Load all relationships
        $sourceVersion->load([
            'stages.sections.fields.rules',
            'stages.accessRule',
            'stageTransitions.actions'
        ]);

        // Maps to track old IDs to new IDs for relationships
        $stageIdMap = [];
        $fieldIdMap = [];

        // Clone stages, sections, fields, and field rules
        $stages = Stage::where('form_version_id', $sourceVersion->id)
            ->with(['sections.fields.rules', 'accessRule'])
            ->get();

        foreach ($stages as $sourceStage) {
            $newStage = Stage::create([
                'form_version_id' => $targetVersion->id,
                'name' => $sourceStage->name,
                'is_initial' => $sourceStage->is_initial,
                'visibility_condition' => $sourceStage->visibility_condition,
            ]);

            $stageIdMap[$sourceStage->id] = $newStage->id;

            // Clone stage access rule if exists
            if ($sourceStage->accessRule) {
                $newAccessRule = StageAccessRule::create([
                    'stage_id' => $newStage->id,
                    'allowed_users' => $sourceStage->accessRule->allowed_users,
                    'allowed_roles' => $sourceStage->accessRule->allowed_roles,
                    'allowed_permissions' => $sourceStage->accessRule->allowed_permissions,
                    'allow_authenticated_users' => $sourceStage->accessRule->allow_authenticated_users,
                    'email_field_id' => null, // Will update later after fields are copied
                ]);

                // Store old email_field_id for later mapping
                if ($sourceStage->accessRule->email_field_id) {
                    $newStage->_temp_old_email_field_id = $sourceStage->accessRule->email_field_id;
                    $newStage->_temp_access_rule_id = $newAccessRule->id;
                }
            }

            // Clone sections and fields
            foreach ($sourceStage->sections as $sourceSection) {
                $newSection = Section::create([
                    'stage_id' => $newStage->id,
                    'name' => $sourceSection->name,
                    'order' => $sourceSection->order,
                    'visibility_conditions' => $sourceSection->visibility_conditions,
                ]);

                foreach ($sourceSection->fields as $sourceField) {
                    $newField = Field::create([
                        'section_id' => $newSection->id,
                        'field_type_id' => $sourceField->field_type_id,
                        'label' => $sourceField->label,
                        'helper_text' => $sourceField->helper_text,
                        'placeholder' => $sourceField->placeholder,
                        'default_value' => $sourceField->default_value,
                        'visibility_conditions' => $sourceField->visibility_conditions,
                    ]);

                    $fieldIdMap[$sourceField->id] = $newField->id;

                    // Clone field rules
                    foreach ($sourceField->rules as $sourceRule) {
                        FieldRule::create([
                            'field_id' => $newField->id,
                            'input_rule_id' => $sourceRule->input_rule_id,
                            'rule_props' => $sourceRule->rule_props,
                            'rule_condition' => $sourceRule->rule_condition,
                        ]);
                    }
                }
            }
        }

        // Update email_field_id references in access rules
        foreach ($stages as $sourceStage) {
            if (isset($stageIdMap[$sourceStage->id])) {
                $newStageId = $stageIdMap[$sourceStage->id];
                $accessRule = StageAccessRule::where('stage_id', $newStageId)->first();
                
                if ($accessRule && $sourceStage->accessRule && $sourceStage->accessRule->email_field_id) {
                    $oldEmailFieldId = $sourceStage->accessRule->email_field_id;
                    if (isset($fieldIdMap[$oldEmailFieldId])) {
                        $accessRule->update(['email_field_id' => $fieldIdMap[$oldEmailFieldId]]);
                    }
                }
            }
        }

        // Clone stage transitions
        $sourceTransitions = StageTransition::where('form_version_id', $sourceVersion->id)
            ->with('actions')
            ->get();

        foreach ($sourceTransitions as $sourceTransition) {
            $newTransition = StageTransition::create([
                'form_version_id' => $targetVersion->id,
                'from_stage_id' => $stageIdMap[$sourceTransition->from_stage_id] ?? null,
                'to_stage_id' => $sourceTransition->to_stage_id ? ($stageIdMap[$sourceTransition->to_stage_id] ?? null) : null,
                'to_complete' => $sourceTransition->to_complete,
                'label' => $sourceTransition->label,
                'condition' => $sourceTransition->condition,
            ]);

            // Clone transition actions
            foreach ($sourceTransition->actions as $sourceAction) {
                StageTransitionAction::create([
                    'stage_transition_id' => $newTransition->id,
                    'action_id' => $sourceAction->action_id,
                    'action_props' => $sourceAction->action_props,
                ]);
            }
        }
    }

    /**
     * Update a draft form version
     */
    public function updateFormVersion(int $id, array $data)
    {
        DB::beginTransaction();

        try {
            $formVersion = FormVersion::findOrFail($id);

            if ($formVersion->status !== 'draft') {
                throw new \Exception('Only draft versions can be updated.');
            }

            // Delete existing stages, sections, fields (cascade will handle related records)
            Stage::where('form_version_id', $id)->delete();
            StageTransition::where('form_version_id', $id)->delete();

            // Maps to track old IDs to new IDs for relationships
            $stageIdMap = [];
            $fieldIdMap = [];

            // Create new structure from data
            foreach ($data['stages'] as $stageData) {
                $stage = Stage::create([
                    'form_version_id' => $formVersion->id,
                    'name' => $stageData['name'],
                    'is_initial' => $stageData['is_initial'],
                    'visibility_condition' => $stageData['visibility_condition'] ?? null,
                ]);

                // Store old ID to new ID mapping if provided
                if (isset($stageData['id'])) {
                    $stageIdMap[$stageData['id']] = $stage->id;
                }

                // Create stage access rule if provided
                if (isset($stageData['access_rule'])) {
                    $accessRuleData = [
                        'stage_id' => $stage->id,
                        'allowed_users' => $stageData['access_rule']['allowed_users'] ?? null,
                        'allowed_roles' => $stageData['access_rule']['allowed_roles'] ?? null,
                        'allowed_permissions' => $stageData['access_rule']['allowed_permissions'] ?? null,
                        'allow_authenticated_users' => $stageData['access_rule']['allow_authenticated_users'] ?? false,
                        'email_field_id' => null, // Will be updated after fields are created
                    ];

                    $accessRule = StageAccessRule::create($accessRuleData);

                    // Store for later email_field_id mapping
                    if (isset($stageData['access_rule']['email_field_id'])) {
                        $stage->_temp_email_field_id = $stageData['access_rule']['email_field_id'];
                        $stage->_temp_access_rule_id = $accessRule->id;
                    }
                }

                foreach ($stageData['sections'] as $sectionData) {
                    $section = Section::create([
                        'stage_id' => $stage->id,
                        'name' => $sectionData['name'],
                        'order' => $sectionData['order'],
                        'visibility_conditions' => $sectionData['visibility_conditions'] ?? null,
                    ]);

                    foreach ($sectionData['fields'] as $fieldData) {
                        $field = Field::create([
                            'section_id' => $section->id,
                            'field_type_id' => $fieldData['field_type_id'],
                            'label' => $fieldData['label'],
                            'helper_text' => $fieldData['helper_text'] ?? null,
                            'placeholder' => $fieldData['placeholder'] ?? null,
                            'default_value' => $fieldData['default_value'] ?? null,
                            'visibility_conditions' => $fieldData['visibility_conditions'] ?? null,
                        ]);

                        // Store old ID to new ID mapping if provided
                        if (isset($fieldData['id'])) {
                            $fieldIdMap[$fieldData['id']] = $field->id;
                        }

                        // Create field rules if provided
                        if (isset($fieldData['rules']) && is_array($fieldData['rules'])) {
                            foreach ($fieldData['rules'] as $ruleData) {
                                FieldRule::create([
                                    'field_id' => $field->id,
                                    'input_rule_id' => $ruleData['input_rule_id'],
                                    'rule_props' => $ruleData['rule_props'] ?? null,
                                    'rule_condition' => $ruleData['rule_condition'] ?? null,
                                ]);
                            }
                        }
                    }
                }
            }

            // Update email_field_id in access rules now that fields are created
            foreach ($data['stages'] as $stageIndex => $stageData) {
                if (isset($stageData['access_rule']['email_field_id'])) {
                    $oldEmailFieldId = $stageData['access_rule']['email_field_id'];
                    
                    // Get the new stage ID
                    $newStageId = isset($stageData['id']) && isset($stageIdMap[$stageData['id']]) 
                        ? $stageIdMap[$stageData['id']] 
                        : null;

                    // If we have the new stage and the field mapping exists
                    if ($newStageId && isset($fieldIdMap[$oldEmailFieldId])) {
                        StageAccessRule::where('stage_id', $newStageId)
                            ->update(['email_field_id' => $fieldIdMap[$oldEmailFieldId]]);
                    }
                }
            }

            // Create stage transitions if provided
            if (isset($data['stage_transitions']) && is_array($data['stage_transitions'])) {
                foreach ($data['stage_transitions'] as $transitionData) {
                    // Map old stage IDs to new ones
                    $fromStageId = isset($transitionData['from_stage_id']) && isset($stageIdMap[$transitionData['from_stage_id']])
                        ? $stageIdMap[$transitionData['from_stage_id']]
                        : $transitionData['from_stage_id'];

                    $toStageId = null;
                    if (isset($transitionData['to_stage_id'])) {
                        $toStageId = isset($stageIdMap[$transitionData['to_stage_id']])
                            ? $stageIdMap[$transitionData['to_stage_id']]
                            : $transitionData['to_stage_id'];
                    }

                    $transition = StageTransition::create([
                        'form_version_id' => $formVersion->id,
                        'from_stage_id' => $fromStageId,
                        'to_stage_id' => $toStageId,
                        'to_complete' => $transitionData['to_complete'] ?? false,
                        'label' => $transitionData['label'],
                        'condition' => $transitionData['condition'] ?? null,
                    ]);

                    // Create transition actions if provided
                    if (isset($transitionData['actions']) && is_array($transitionData['actions'])) {
                        foreach ($transitionData['actions'] as $actionData) {
                            StageTransitionAction::create([
                                'stage_transition_id' => $transition->id,
                                'action_id' => $actionData['action_id'],
                                'action_props' => $actionData['action_props'] ?? null,
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            return $formVersion->load([
                'stages.sections.fields.rules.inputRule',
                'stages.accessRule',
                'stageTransitions.fromStage',
                'stageTransitions.toStage',
                'stageTransitions.actions.action'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Publish a draft form version
     */
    public function publishFormVersion(int $id)
    {
        DB::beginTransaction();

        try {
            $formVersion = FormVersion::findOrFail($id);

            if ($formVersion->status !== 'draft') {
                throw new \Exception('Only draft versions can be published.');
            }

            // Set all other versions of this form as non-published
            FormVersion::where('form_id', $formVersion->form_id)
                ->where('id', '!=', $id)
                ->update(['status' => 'draft']);

            // Publish this version
            $formVersion->update([
                'status' => 'published',
                'published_at' => now(),
            ]);

            DB::commit();

            return $formVersion;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get form version by ID with full structure
     */
    public function getFormVersionById(int $id)
    {
        return FormVersion::with([
            'form',
            'stages.sections.fields.fieldType',
            'stages.sections.fields.rules.inputRule',
            'stages.accessRule',
            'stageTransitions.fromStage',
            'stageTransitions.toStage',
            'stageTransitions.actions.action'
        ])->findOrFail($id);
    }

    /**
     * Get all versions of a form
     */
    public function getFormVersions(int $formId)
    {
        return FormVersion::where('form_id', $formId)
            ->orderBy('version_number', 'desc')
            ->get();
    }
}
