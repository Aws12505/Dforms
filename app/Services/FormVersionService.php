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
     * Helper function to check if an ID is a fake ID
     */
    private function isFakeId($id): bool
    {
        if ($id === null) {
            return false;
        }
        return is_string($id) && (str_starts_with($id, 'FAKE_') || str_starts_with($id, 'temp_'));
    }

    /**
     * Recursively resolve all fake IDs in a nested array/object structure
     * This handles visibility_conditions, rule_conditions, and transition conditions
     */
    private function resolveFakeIdsInData($data, array $stageIdMap, array $sectionIdMap, array $fieldIdMap, array $transitionIdMap)
    {
        if (is_array($data)) {
            $resolved = [];
            foreach ($data as $key => $value) {
                // Check if the key itself might indicate an ID field
                if (is_string($key) && (
                    str_contains($key, 'stage_id') || 
                    str_contains($key, 'section_id') || 
                    str_contains($key, 'field_id') ||
                    str_contains($key, 'transition_id')
                )) {
                    // Resolve the value if it's a fake ID
                    if ($this->isFakeId($value)) {
                        if (str_contains($key, 'stage')) {
                            $resolved[$key] = $stageIdMap[$value] ?? $value;
                        } elseif (str_contains($key, 'section')) {
                            $resolved[$key] = $sectionIdMap[$value] ?? $value;
                        } elseif (str_contains($key, 'field')) {
                            $resolved[$key] = $fieldIdMap[$value] ?? $value;
                        } elseif (str_contains($key, 'transition')) {
                            $resolved[$key] = $transitionIdMap[$value] ?? $value;
                        } else {
                            $resolved[$key] = $value;
                        }
                    } else {
                        $resolved[$key] = $this->resolveFakeIdsInData($value, $stageIdMap, $sectionIdMap, $fieldIdMap, $transitionIdMap);
                    }
                } else {
                    // Recursively process nested structures
                    $resolved[$key] = $this->resolveFakeIdsInData($value, $stageIdMap, $sectionIdMap, $fieldIdMap, $transitionIdMap);
                }
            }
            return $resolved;
        } elseif (is_string($data) && $this->isFakeId($data)) {
            // Direct ID value - try to resolve it
            // Check all maps
            if (isset($stageIdMap[$data])) {
                return $stageIdMap[$data];
            } elseif (isset($sectionIdMap[$data])) {
                return $sectionIdMap[$data];
            } elseif (isset($fieldIdMap[$data])) {
                return $fieldIdMap[$data];
            } elseif (isset($transitionIdMap[$data])) {
                return $transitionIdMap[$data];
            }
            return $data; // Return as-is if not found in any map
        } else {
            return $data;
        }
    }

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
                'stages.sections.fields.fieldType',
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
     * Update a draft form version with support for fake IDs
     * Handles ALL ID references including in visibility_conditions and rule_conditions
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

        // Maps to track fake/old IDs to new real IDs
        $stageIdMap = [];
        $sectionIdMap = [];
        $fieldIdMap = [];
        $transitionIdMap = [];

        // Temporary storage for entities that need post-processing
        $stagesToUpdate = [];
        $sectionsToUpdate = [];
        $fieldsToUpdate = [];
        $fieldRulesToUpdate = [];
        $accessRulesToCreate = [];
        $transitionsToUpdate = [];

        // ============================================================
        // PASS 1: Create all stages (WITHOUT visibility conditions yet)
        // ============================================================
        foreach ($data['stages'] as $stageIndex => $stageData) {
            $stage = Stage::create([
                'form_version_id' => $formVersion->id,
                'name' => $stageData['name'],
                'is_initial' => $stageData['is_initial'],
                'visibility_condition' => null, // Will be set in pass 5
            ]);

            // Map fake or old ID to new real ID
            if (isset($stageData['id'])) {
                $stageIdMap[$stageData['id']] = $stage->id;
            }

            // Store for later processing
            $stagesToUpdate[] = [
                'stage' => $stage,
                'original_data' => $stageData
            ];
        }

        // ============================================================
        // PASS 2: Create sections and fields (WITHOUT visibility conditions yet)
        // ============================================================
        foreach ($stagesToUpdate as $stageInfo) {
            $stage = $stageInfo['stage'];
            $stageData = $stageInfo['original_data'];

            foreach ($stageData['sections'] as $sectionIndex => $sectionData) {
                $section = Section::create([
                    'stage_id' => $stage->id,
                    'name' => $sectionData['name'],
                    'order' => $sectionData['order'],
                    'visibility_conditions' => null, // Will be set in pass 5
                ]);

                // Map fake or old ID to new real ID
                if (isset($sectionData['id'])) {
                    $sectionIdMap[$sectionData['id']] = $section->id;
                }

                // Store for later processing
                $sectionsToUpdate[] = [
                    'section' => $section,
                    'original_data' => $sectionData
                ];

                foreach ($sectionData['fields'] as $fieldIndex => $fieldData) {
                    $field = Field::create([
                        'section_id' => $section->id,
                        'field_type_id' => $fieldData['field_type_id'],
                        'label' => $fieldData['label'],
                        'helper_text' => $fieldData['helper_text'] ?? null,
                        'placeholder' => $fieldData['placeholder'] ?? null,
                        'default_value' => $fieldData['default_value'] ?? null,
                        'visibility_conditions' => null, // Will be set in pass 5
                    ]);

                    // Map fake or old ID to new real ID
                    if (isset($fieldData['id'])) {
                        $fieldIdMap[$fieldData['id']] = $field->id;
                    }

                    // Store for later processing
                    $fieldsToUpdate[] = [
                        'field' => $field,
                        'original_data' => $fieldData
                    ];
                }
            }
        }

        // ============================================================
        // PASS 3: Create field rules (WITHOUT rule_conditions yet)
        // ============================================================
        foreach ($fieldsToUpdate as $fieldInfo) {
            $field = $fieldInfo['field'];
            $fieldData = $fieldInfo['original_data'];

            if (isset($fieldData['rules']) && is_array($fieldData['rules'])) {
                foreach ($fieldData['rules'] as $ruleData) {
                    $fieldRule = FieldRule::create([
                        'field_id' => $field->id,
                        'input_rule_id' => $ruleData['input_rule_id'],
                        'rule_props' => $ruleData['rule_props'] ?? null,
                        'rule_condition' => null, // Will be set in pass 5
                    ]);

                    // Store for later processing
                    $fieldRulesToUpdate[] = [
                        'field_rule' => $fieldRule,
                        'original_data' => $ruleData
                    ];
                }
            }
        }

        // ============================================================
        // PASS 4: Create stage access rules
        // ============================================================
        foreach ($stagesToUpdate as $stageInfo) {
            $stage = $stageInfo['stage'];
            $stageData = $stageInfo['original_data'];

            if (isset($stageData['access_rule'])) {
                $accessRuleData = $stageData['access_rule'];

                // Resolve email_field_id if it's a fake ID
                $emailFieldId = null;
                if (isset($accessRuleData['email_field_id'])) {
                    $providedEmailFieldId = $accessRuleData['email_field_id'];

                    if ($this->isFakeId($providedEmailFieldId)) {
                        $emailFieldId = $fieldIdMap[$providedEmailFieldId] ?? null;
                    } else {
                        $emailFieldId = $providedEmailFieldId;
                    }
                }

                StageAccessRule::create([
                    'stage_id' => $stage->id,
                    'allowed_users' => $accessRuleData['allowed_users'] ?? null,
                    'allowed_roles' => $accessRuleData['allowed_roles'] ?? null,
                    'allowed_permissions' => $accessRuleData['allowed_permissions'] ?? null,
                    'allow_authenticated_users' => $accessRuleData['allow_authenticated_users'] ?? false,
                    'email_field_id' => $emailFieldId,
                ]);
            }
        }

        // ============================================================
        // PASS 5: Update visibility conditions and rule conditions
        // Now all IDs exist, so we can resolve fake IDs in conditions
        // ============================================================

        // Update stage visibility conditions
        foreach ($stagesToUpdate as $stageInfo) {
            $stage = $stageInfo['stage'];
            $stageData = $stageInfo['original_data'];

            if (isset($stageData['visibility_condition'])) {
                $resolvedCondition = $this->resolveFakeIdsInData(
                    $stageData['visibility_condition'],
                    $stageIdMap,
                    $sectionIdMap,
                    $fieldIdMap,
                    $transitionIdMap
                );
                $stage->update(['visibility_condition' => $resolvedCondition]);
            }
        }

        // Update section visibility conditions
        foreach ($sectionsToUpdate as $sectionInfo) {
            $section = $sectionInfo['section'];
            $sectionData = $sectionInfo['original_data'];

            if (isset($sectionData['visibility_conditions'])) {
                $resolvedConditions = $this->resolveFakeIdsInData(
                    $sectionData['visibility_conditions'],
                    $stageIdMap,
                    $sectionIdMap,
                    $fieldIdMap,
                    $transitionIdMap
                );
                $section->update(['visibility_conditions' => $resolvedConditions]);
            }
        }

        // Update field visibility conditions
        foreach ($fieldsToUpdate as $fieldInfo) {
            $field = $fieldInfo['field'];
            $fieldData = $fieldInfo['original_data'];

            if (isset($fieldData['visibility_conditions'])) {
                $resolvedConditions = $this->resolveFakeIdsInData(
                    $fieldData['visibility_conditions'],
                    $stageIdMap,
                    $sectionIdMap,
                    $fieldIdMap,
                    $transitionIdMap
                );
                $field->update(['visibility_conditions' => $resolvedConditions]);
            }
        }

        // Update field rule conditions
        foreach ($fieldRulesToUpdate as $ruleInfo) {
            $fieldRule = $ruleInfo['field_rule'];
            $ruleData = $ruleInfo['original_data'];

            if (isset($ruleData['rule_condition'])) {
                $resolvedCondition = $this->resolveFakeIdsInData(
                    $ruleData['rule_condition'],
                    $stageIdMap,
                    $sectionIdMap,
                    $fieldIdMap,
                    $transitionIdMap
                );
                $fieldRule->update(['rule_condition' => $resolvedCondition]);
            }
        }

        // ============================================================
        // PASS 6: Create stage transitions (FIXED)
        // ============================================================
        if (isset($data['stage_transitions']) && is_array($data['stage_transitions'])) {
            foreach ($data['stage_transitions'] as $transitionData) {
                // Resolve from_stage_id - CHECK MAP FIRST (both fake and real IDs)
                $fromStageId = $transitionData['from_stage_id'];
                if (isset($stageIdMap[$fromStageId])) {
                    // ID exists in map (could be fake or real), use the mapped value
                    $fromStageId = $stageIdMap[$fromStageId];
                } elseif ($this->isFakeId($fromStageId)) {
                    // It's a fake ID but not in map - set to null
                    $fromStageId = null;
                }
                // else: it's a real ID not in map, keep it as is (unlikely scenario)

                // Resolve to_stage_id - CHECK MAP FIRST (both fake and real IDs)
                $toStageId = null;
                if (isset($transitionData['to_stage_id']) && $transitionData['to_stage_id'] !== null) {
                    $toStageId = $transitionData['to_stage_id'];
                    if (isset($stageIdMap[$toStageId])) {
                        // ID exists in map (could be fake or real), use the mapped value
                        $toStageId = $stageIdMap[$toStageId];
                    } elseif ($this->isFakeId($toStageId)) {
                        // It's a fake ID but not in map - set to null
                        $toStageId = null;
                    }
                    // else: it's a real ID not in map, keep it as is (unlikely scenario)
                }

                // Resolve condition (may contain fake IDs)
                $condition = null;
                if (isset($transitionData['condition'])) {
                    $condition = $this->resolveFakeIdsInData(
                        $transitionData['condition'],
                        $stageIdMap,
                        $sectionIdMap,
                        $fieldIdMap,
                        $transitionIdMap
                    );
                }

                $transition = StageTransition::create([
                    'form_version_id' => $formVersion->id,
                    'from_stage_id' => $fromStageId,
                    'to_stage_id' => $toStageId,
                    'to_complete' => $transitionData['to_complete'] ?? false,
                    'label' => $transitionData['label'],
                    'condition' => $condition,
                ]);

                // Map fake or old ID to new real ID
                if (isset($transitionData['id'])) {
                    $transitionIdMap[$transitionData['id']] = $transition->id;
                }

                // Create transition actions
                if (isset($transitionData['actions']) && is_array($transitionData['actions'])) {
                    foreach ($transitionData['actions'] as $actionData) {
                        // Resolve action props (may contain fake IDs)
                        $actionProps = null;
                        if (isset($actionData['action_props'])) {
                            $actionProps = $this->resolveFakeIdsInData(
                                $actionData['action_props'],
                                $stageIdMap,
                                $sectionIdMap,
                                $fieldIdMap,
                                $transitionIdMap
                            );
                        }

                        StageTransitionAction::create([
                            'stage_transition_id' => $transition->id,
                            'action_id' => $actionData['action_id'],
                            'action_props' => $actionProps,
                        ]);
                    }
                }
            }
        }

        DB::commit();

        return $formVersion->load([
            'stages.sections.fields.rules.inputRule',
            'stages.sections.fields.fieldType',
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
