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
     * Normalize IDs to a consistent map key (handles int vs string).
     */
    private function idKey($id): ?string
    {
        if ($id === null) return null;
        return is_scalar($id) ? (string) $id : null;
    }

    /**
     * Helper to check if a string is valid JSON
     */
    private function isJsonString($string): bool
    {
        if (!is_string($string)) {
            return false;
        }
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Recursively resolve ALL mapped IDs (fake OR old real IDs) in a nested array/object structure.
     * This handles visibility_condition(s), rule_condition(s), rule_props, and action_props.
     */
    private function resolveIdsInData($data, array $stageIdMap, array $sectionIdMap, array $fieldIdMap, array $transitionIdMap)
    {
        $tryMap = function ($value, array $map) {
            $key = $this->idKey($value);
            if ($key !== null && array_key_exists($key, $map)) {
                return $map[$key];
            }
            return $value;
        };

        if (is_array($data)) {
            $resolved = [];
            foreach ($data as $key => $value) {
                // If key indicates an ID field, map the value (fake OR old real)
                if (is_string($key) && (
                    str_contains($key, 'stage_id') ||
                    str_contains($key, 'section_id') ||
                    str_contains($key, 'field_id') ||
                    str_contains($key, 'transition_id') ||
                    // Common prop keys that may contain field IDs
                    $key === 'comparevalue' ||
                    $key === 'compare_field' ||
                    $key === 'field' ||
                    $key === 'target_field_id'
                )) {
                    if (str_contains($key, 'stage') || $key === 'stage_id') {
                        $resolved[$key] = $tryMap($value, $stageIdMap);
                    } elseif (str_contains($key, 'section') || $key === 'section_id') {
                        $resolved[$key] = $tryMap($value, $sectionIdMap);
                    } elseif (str_contains($key, 'transition') || $key === 'transition_id') {
                        $resolved[$key] = $tryMap($value, $transitionIdMap);
                    } else {
                        $resolved[$key] = $tryMap($value, $fieldIdMap);
                    }
                } else {
                    $resolved[$key] = $this->resolveIdsInData($value, $stageIdMap, $sectionIdMap, $fieldIdMap, $transitionIdMap);
                }
            }
            return $resolved;
        }

        if (is_string($data) && $this->isJsonString($data)) {
            $decoded = json_decode($data, true);
            $resolved = $this->resolveIdsInData($decoded, $stageIdMap, $sectionIdMap, $fieldIdMap, $transitionIdMap);
            return json_encode($resolved);
        }

        // Scalar leaf: if it matches any map key, replace (fake OR old real)
        $key = $this->idKey($data);
        if ($key !== null) {
            if (array_key_exists($key, $fieldIdMap)) return $fieldIdMap[$key];
            if (array_key_exists($key, $stageIdMap)) return $stageIdMap[$key];
            if (array_key_exists($key, $sectionIdMap)) return $sectionIdMap[$key];
            if (array_key_exists($key, $transitionIdMap)) return $transitionIdMap[$key];
        }

        return $data;
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

            // Map old real ID -> new real ID
            $stageIdMap[$this->idKey($sourceStage->id)] = $newStage->id;

            // Clone stage access rule if exists
            if ($sourceStage->accessRule) {
                StageAccessRule::create([
                    'stage_id' => $newStage->id,
                    'allowed_users' => $sourceStage->accessRule->allowed_users,
                    'allowed_roles' => $sourceStage->accessRule->allowed_roles,
                    'allowed_permissions' => $sourceStage->accessRule->allowed_permissions,
                    'allow_authenticated_users' => $sourceStage->accessRule->allow_authenticated_users,
                    'email_field_id' => null, // Will update later after fields are copied
                ]);
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

                    // Map old real ID -> new real ID
                    $fieldIdMap[$this->idKey($sourceField->id)] = $newField->id;

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
            $newStageId = $stageIdMap[$this->idKey($sourceStage->id)] ?? null;
            if (!$newStageId) continue;

            $accessRule = StageAccessRule::where('stage_id', $newStageId)->first();
            if (!$accessRule) continue;

            if ($sourceStage->accessRule && $sourceStage->accessRule->email_field_id) {
                $oldEmailFieldIdKey = $this->idKey($sourceStage->accessRule->email_field_id);
                if ($oldEmailFieldIdKey !== null && isset($fieldIdMap[$oldEmailFieldIdKey])) {
                    $accessRule->update(['email_field_id' => $fieldIdMap[$oldEmailFieldIdKey]]);
                }
            }
        }

        // Clone stage transitions
        $sourceTransitions = StageTransition::where('form_version_id', $sourceVersion->id)
            ->with('actions')
            ->get();

        foreach ($sourceTransitions as $sourceTransition) {
            $fromStageId = $stageIdMap[$this->idKey($sourceTransition->from_stage_id)] ?? null;
            $toStageId = $sourceTransition->to_stage_id
                ? ($stageIdMap[$this->idKey($sourceTransition->to_stage_id)] ?? null)
                : null;

            $newTransition = StageTransition::create([
                'form_version_id' => $targetVersion->id,
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $toStageId,
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
     * Update a draft form version with support for fake IDs AND old real IDs
     * Handles ALL ID references including in visibility_condition(s), rule_condition(s), rule_props, action_props
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

            // Maps to track incoming IDs (fake OR old real) -> new real IDs
            $stageIdMap = [];
            $sectionIdMap = [];
            $fieldIdMap = [];
            $transitionIdMap = [];

            // Temporary storage for entities that need post-processing
            $stagesToUpdate = [];
            $sectionsToUpdate = [];
            $fieldsToUpdate = [];
            $fieldRulesToUpdate = [];

            // ============================================================
            // PASS 1: Create all stages (WITHOUT visibility conditions yet)
            // ============================================================
            foreach ($data['stages'] as $stageData) {
                $stage = Stage::create([
                    'form_version_id' => $formVersion->id,
                    'name' => $stageData['name'],
                    'is_initial' => $stageData['is_initial'],
                    'visibility_condition' => null, // set in pass 5
                ]);

                // Map provided (fake OR old real) ID -> new real ID
                if (isset($stageData['id'])) {
                    $k = $this->idKey($stageData['id']);
                    if ($k !== null) $stageIdMap[$k] = $stage->id;
                }

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

                foreach ($stageData['sections'] as $sectionData) {
                    $section = Section::create([
                        'stage_id' => $stage->id,
                        'name' => $sectionData['name'],
                        'order' => $sectionData['order'],
                        'visibility_condition' => null, // FIXED: singular
                    ]);

                    // Map provided (fake OR old real) ID -> new real ID
                    if (isset($sectionData['id'])) {
                        $k = $this->idKey($sectionData['id']);
                        if ($k !== null) $sectionIdMap[$k] = $section->id;
                    }

                    $sectionsToUpdate[] = [
                        'section' => $section,
                        'original_data' => $sectionData
                    ];

                    foreach ($sectionData['fields'] as $fieldData) {
                        $field = Field::create([
                            'section_id' => $section->id,
                            'field_type_id' => $fieldData['field_type_id'],
                            'label' => $fieldData['label'],
                            'helper_text' => $fieldData['helper_text'] ?? null,
                            'placeholder' => $fieldData['placeholder'] ?? null,
                            'default_value' => $fieldData['default_value'] ?? null,
                            'visibility_condition' => null, // FIXED: singular
                        ]);

                        // Map provided (fake OR old real) ID -> new real ID
                        if (isset($fieldData['id'])) {
                            $k = $this->idKey($fieldData['id']);
                            if ($k !== null) $fieldIdMap[$k] = $field->id;
                        }

                        $fieldsToUpdate[] = [
                            'field' => $field,
                            'original_data' => $fieldData
                        ];
                    }
                }
            }

            // ============================================================
            // PASS 3: Create field rules (WITHOUT rule_condition yet)
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
                            'rule_condition' => null, // set in pass 5
                        ]);

                        $fieldRulesToUpdate[] = [
                            'field_rule' => $fieldRule,
                            'original_data' => $ruleData
                        ];
                    }
                }
            }

            // ============================================================
            // PASS 4: Create stage access rules (resolve email_field_id by map)
            // ============================================================
            foreach ($stagesToUpdate as $stageInfo) {
                $stage = $stageInfo['stage'];
                $stageData = $stageInfo['original_data'];

                if (isset($stageData['access_rule'])) {
                    $accessRuleData = $stageData['access_rule'];

                    $emailFieldId = null;
                    if (array_key_exists('email_field_id', $accessRuleData)) {
                        $providedEmailFieldId = $accessRuleData['email_field_id'];
                        $key = $this->idKey($providedEmailFieldId);

                        if ($key !== null && array_key_exists($key, $fieldIdMap)) {
                            $emailFieldId = $fieldIdMap[$key];
                        } elseif ($this->isFakeId($providedEmailFieldId)) {
                            // fake but not mapped -> null
                            $emailFieldId = null;
                        } else {
                            // real ID (not mapped) - keep
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
            // PASS 5: Update visibility conditions and rule conditions/props
            // ============================================================

            // Stage visibility_condition
            foreach ($stagesToUpdate as $stageInfo) {
                $stage = $stageInfo['stage'];
                $stageData = $stageInfo['original_data'];

                if (isset($stageData['visibility_condition'])) {
                    $resolvedCondition = $this->resolveIdsInData(
                        $stageData['visibility_condition'],
                        $stageIdMap,
                        $sectionIdMap,
                        $fieldIdMap,
                        $transitionIdMap
                    );
                    $stage->update(['visibility_condition' => $resolvedCondition]);
                }
            }

            // Section visibility_condition (incoming key is visibility_conditions)
            foreach ($sectionsToUpdate as $sectionInfo) {
                $section = $sectionInfo['section'];
                $sectionData = $sectionInfo['original_data'];

                if (isset($sectionData['visibility_conditions'])) {
                    $resolvedConditions = $this->resolveIdsInData(
                        $sectionData['visibility_conditions'],
                        $stageIdMap,
                        $sectionIdMap,
                        $fieldIdMap,
                        $transitionIdMap
                    );
                    $section->update(['visibility_condition' => $resolvedConditions]);
                }
            }

            // Field visibility_condition (incoming key is visibility_conditions)
            foreach ($fieldsToUpdate as $fieldInfo) {
                $field = $fieldInfo['field'];
                $fieldData = $fieldInfo['original_data'];

                if (isset($fieldData['visibility_conditions'])) {
                    $resolvedConditions = $this->resolveIdsInData(
                        $fieldData['visibility_conditions'],
                        $stageIdMap,
                        $sectionIdMap,
                        $fieldIdMap,
                        $transitionIdMap
                    );
                    $field->update(['visibility_condition' => $resolvedConditions]);
                }
            }

            // FieldRule rule_condition + rule_props
            foreach ($fieldRulesToUpdate as $ruleInfo) {
                $fieldRule = $ruleInfo['field_rule'];
                $ruleData = $ruleInfo['original_data'];

                $updateData = [];

                if (isset($ruleData['rule_condition'])) {
                    $updateData['rule_condition'] = $this->resolveIdsInData(
                        $ruleData['rule_condition'],
                        $stageIdMap,
                        $sectionIdMap,
                        $fieldIdMap,
                        $transitionIdMap
                    );
                }

                if (isset($ruleData['rule_props'])) {
                    $updateData['rule_props'] = $this->resolveIdsInData(
                        $ruleData['rule_props'],
                        $stageIdMap,
                        $sectionIdMap,
                        $fieldIdMap,
                        $transitionIdMap
                    );
                }

                if (!empty($updateData)) {
                    $fieldRule->update($updateData);
                }
            }

            // ============================================================
            // PASS 6: Create stage transitions + actions (map-first, not fake-only)
            // ============================================================
            if (isset($data['stage_transitions']) && is_array($data['stage_transitions'])) {
                foreach ($data['stage_transitions'] as $transitionData) {

                    // Resolve from_stage_id (map-first)
                    $fromStageId = $transitionData['from_stage_id'] ?? null;
                    $fromKey = $this->idKey($fromStageId);
                    if ($fromKey !== null && array_key_exists($fromKey, $stageIdMap)) {
                        $fromStageId = $stageIdMap[$fromKey];
                    } elseif ($this->isFakeId($fromStageId)) {
                        $fromStageId = null;
                    }

                    // Resolve to_stage_id (map-first)
                    $toStageId = null;
                    if (array_key_exists('to_stage_id', $transitionData) && $transitionData['to_stage_id'] !== null) {
                        $toStageId = $transitionData['to_stage_id'];
                        $toKey = $this->idKey($toStageId);
                        if ($toKey !== null && array_key_exists($toKey, $stageIdMap)) {
                            $toStageId = $stageIdMap[$toKey];
                        } elseif ($this->isFakeId($toStageId)) {
                            $toStageId = null;
                        }
                    }

                    // Resolve condition (may contain mapped IDs)
                    $condition = null;
                    if (isset($transitionData['condition'])) {
                        $condition = $this->resolveIdsInData(
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

                    // Map provided (fake OR old real) transition ID -> new real ID
                    if (isset($transitionData['id'])) {
                        $k = $this->idKey($transitionData['id']);
                        if ($k !== null) $transitionIdMap[$k] = $transition->id;
                    }

                    // Create transition actions
                    if (isset($transitionData['actions']) && is_array($transitionData['actions'])) {
                        foreach ($transitionData['actions'] as $actionData) {

                            $actionProps = null;
                            if (isset($actionData['action_props'])) {
                                $actionProps = $this->resolveIdsInData(
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
