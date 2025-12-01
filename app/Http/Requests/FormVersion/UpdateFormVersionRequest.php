<?php

namespace App\Http\Requests\FormVersion;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFormVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ========== STAGES ==========
            'stages' => 'required|array',
            'stages.*.id' => 'nullable|integer|exists:stages,id',
            'stages.*.name' => 'required|string|max:255',
            'stages.*.is_initial' => 'required|boolean',
            'stages.*.visibility_condition' => 'nullable|json',
            
            // ========== STAGE ACCESS RULES ==========
            'stages.*.access_rule' => 'nullable|array',
            'stages.*.access_rule.allowed_users' => 'nullable|json',
            'stages.*.access_rule.allowed_roles' => 'nullable|json',
            'stages.*.access_rule.allowed_permissions' => 'nullable|json',
            'stages.*.access_rule.allow_authenticated_users' => 'nullable|boolean',
            'stages.*.access_rule.email_field_id' => 'nullable|integer',
            
            // ========== SECTIONS ==========
            'stages.*.sections' => 'required|array',
            'stages.*.sections.*.id' => 'nullable|integer|exists:sections,id',
            'stages.*.sections.*.name' => 'required|string|max:255',
            'stages.*.sections.*.order' => 'required|integer',
            'stages.*.sections.*.visibility_conditions' => 'nullable|json',
            
            // ========== FIELDS ==========
            'stages.*.sections.*.fields' => 'required|array',
            'stages.*.sections.*.fields.*.id' => 'nullable|integer|exists:fields,id',
            'stages.*.sections.*.fields.*.field_type_id' => 'required|integer|exists:field_types,id',
            'stages.*.sections.*.fields.*.label' => 'required|string|max:255',
            'stages.*.sections.*.fields.*.helper_text' => 'nullable|string',
            'stages.*.sections.*.fields.*.placeholder' => 'nullable|string|max:255',
            'stages.*.sections.*.fields.*.default_value' => 'nullable|string',
            'stages.*.sections.*.fields.*.visibility_conditions' => 'nullable|json',
            
            // ========== FIELD RULES ==========
            'stages.*.sections.*.fields.*.rules' => 'nullable|array',
            'stages.*.sections.*.fields.*.rules.*.id' => 'nullable|integer|exists:field_rules,id',
            'stages.*.sections.*.fields.*.rules.*.input_rule_id' => 'required|integer|exists:input_rules,id',
            'stages.*.sections.*.fields.*.rules.*.rule_props' => 'nullable|json',
            'stages.*.sections.*.fields.*.rules.*.rule_condition' => 'nullable|json',
            
            // ========== STAGE TRANSITIONS ==========
            'stage_transitions' => 'nullable|array',
            'stage_transitions.*.id' => 'nullable|integer|exists:stage_transitions,id',
            'stage_transitions.*.from_stage_id' => 'required|integer',
            'stage_transitions.*.to_stage_id' => 'nullable|integer',
            'stage_transitions.*.to_complete' => 'nullable|boolean',
            'stage_transitions.*.label' => 'required|string|max:255',
            'stage_transitions.*.condition' => 'nullable|json',
            
            // ========== STAGE TRANSITION ACTIONS ==========
            'stage_transitions.*.actions' => 'nullable|array',
            'stage_transitions.*.actions.*.id' => 'nullable|integer|exists:stage_transition_actions,id',
            'stage_transitions.*.actions.*.action_id' => 'required|integer|exists:actions,id',
            'stage_transitions.*.actions.*.action_props' => 'nullable|json',
        ];
    }

    public function messages(): array
    {
        return [
            // ========== STAGES ==========
            'stages.required' => 'Stages are required.',
            'stages.array' => 'Stages must be an array.',
            'stages.*.id.integer' => 'Stage ID must be an integer.',
            'stages.*.id.exists' => 'One or more stages do not exist.',
            'stages.*.name.required' => 'Stage name is required.',
            'stages.*.name.string' => 'Stage name must be a string.',
            'stages.*.name.max' => 'Stage name cannot exceed 255 characters.',
            'stages.*.is_initial.required' => 'Stage initial flag is required.',
            'stages.*.is_initial.boolean' => 'Stage initial flag must be a boolean.',
            'stages.*.visibility_condition.json' => 'Stage visibility condition must be valid JSON.',
            
            // ========== STAGE ACCESS RULES ==========
            'stages.*.access_rule.array' => 'Stage access rule must be an array.',
            'stages.*.access_rule.allowed_users.json' => 'Allowed users must be valid JSON.',
            'stages.*.access_rule.allowed_roles.json' => 'Allowed roles must be valid JSON.',
            'stages.*.access_rule.allowed_permissions.json' => 'Allowed permissions must be valid JSON.',
            'stages.*.access_rule.allow_authenticated_users.boolean' => 'Allow authenticated users must be a boolean.',
            'stages.*.access_rule.email_field_id.integer' => 'Email field ID must be an integer.',
            
            // ========== SECTIONS ==========
            'stages.*.sections.required' => 'Sections are required for each stage.',
            'stages.*.sections.array' => 'Sections must be an array.',
            'stages.*.sections.*.id.integer' => 'Section ID must be an integer.',
            'stages.*.sections.*.id.exists' => 'One or more sections do not exist.',
            'stages.*.sections.*.name.required' => 'Section name is required.',
            'stages.*.sections.*.name.string' => 'Section name must be a string.',
            'stages.*.sections.*.name.max' => 'Section name cannot exceed 255 characters.',
            'stages.*.sections.*.order.required' => 'Section order is required.',
            'stages.*.sections.*.order.integer' => 'Section order must be an integer.',
            'stages.*.sections.*.visibility_conditions.json' => 'Visibility conditions must be valid JSON.',
            
            // ========== FIELDS ==========
            'stages.*.sections.*.fields.required' => 'Fields are required for each section.',
            'stages.*.sections.*.fields.array' => 'Fields must be an array.',
            'stages.*.sections.*.fields.*.id.integer' => 'Field ID must be an integer.',
            'stages.*.sections.*.fields.*.id.exists' => 'One or more fields do not exist.',
            'stages.*.sections.*.fields.*.field_type_id.required' => 'Field type ID is required.',
            'stages.*.sections.*.fields.*.field_type_id.integer' => 'Field type ID must be an integer.',
            'stages.*.sections.*.fields.*.field_type_id.exists' => 'One or more field types do not exist.',
            'stages.*.sections.*.fields.*.label.required' => 'Field label is required.',
            'stages.*.sections.*.fields.*.label.string' => 'Field label must be a string.',
            'stages.*.sections.*.fields.*.label.max' => 'Field label cannot exceed 255 characters.',
            'stages.*.sections.*.fields.*.helper_text.string' => 'Helper text must be a string.',
            'stages.*.sections.*.fields.*.placeholder.string' => 'Placeholder must be a string.',
            'stages.*.sections.*.fields.*.placeholder.max' => 'Placeholder cannot exceed 255 characters.',
            'stages.*.sections.*.fields.*.default_value.string' => 'Default value must be a string.',
            'stages.*.sections.*.fields.*.visibility_conditions.json' => 'Field visibility conditions must be valid JSON.',
            
            // ========== FIELD RULES ==========
            'stages.*.sections.*.fields.*.rules.array' => 'Field rules must be an array.',
            'stages.*.sections.*.fields.*.rules.*.id.integer' => 'Field rule ID must be an integer.',
            'stages.*.sections.*.fields.*.rules.*.id.exists' => 'One or more field rules do not exist.',
            'stages.*.sections.*.fields.*.rules.*.input_rule_id.required' => 'Input rule ID is required for each field rule.',
            'stages.*.sections.*.fields.*.rules.*.input_rule_id.integer' => 'Input rule ID must be an integer.',
            'stages.*.sections.*.fields.*.rules.*.input_rule_id.exists' => 'Selected input rule does not exist.',
            'stages.*.sections.*.fields.*.rules.*.rule_props.json' => 'Rule props must be valid JSON.',
            'stages.*.sections.*.fields.*.rules.*.rule_condition.json' => 'Rule condition must be valid JSON.',
            
            // ========== STAGE TRANSITIONS ==========
            'stage_transitions.array' => 'Stage transitions must be an array.',
            'stage_transitions.*.id.integer' => 'Stage transition ID must be an integer.',
            'stage_transitions.*.id.exists' => 'One or more stage transitions do not exist.',
            'stage_transitions.*.from_stage_id.required' => 'From stage ID is required for each transition.',
            'stage_transitions.*.from_stage_id.integer' => 'From stage ID must be an integer.',
            'stage_transitions.*.to_stage_id.integer' => 'To stage ID must be an integer.',
            'stage_transitions.*.to_complete.boolean' => 'To complete must be a boolean.',
            'stage_transitions.*.label.required' => 'Transition label is required.',
            'stage_transitions.*.label.string' => 'Transition label must be a string.',
            'stage_transitions.*.label.max' => 'Transition label cannot exceed 255 characters.',
            'stage_transitions.*.condition.json' => 'Transition condition must be valid JSON.',
            
            // ========== STAGE TRANSITION ACTIONS ==========
            'stage_transitions.*.actions.array' => 'Transition actions must be an array.',
            'stage_transitions.*.actions.*.id.integer' => 'Transition action ID must be an integer.',
            'stage_transitions.*.actions.*.id.exists' => 'One or more transition actions do not exist.',
            'stage_transitions.*.actions.*.action_id.required' => 'Action ID is required for each transition action.',
            'stage_transitions.*.actions.*.action_id.integer' => 'Action ID must be an integer.',
            'stage_transitions.*.actions.*.action_id.exists' => 'Selected action does not exist.',
            'stage_transitions.*.actions.*.action_props.json' => 'Action props must be valid JSON.',
        ];
    }
}
