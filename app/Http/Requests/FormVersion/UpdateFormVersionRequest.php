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
            'stages' => 'required|array',
            'stages.*.id' => 'nullable|integer|exists:stages,id',
            'stages.*.name' => 'required|string|max:255',
            'stages.*.is_initial' => 'required|boolean',
            'stages.*.sections' => 'required|array',
            'stages.*.sections.*.id' => 'nullable|integer|exists:sections,id',
            'stages.*.sections.*.name' => 'required|string|max:255',
            'stages.*.sections.*.order' => 'required|integer',
            'stages.*.sections.*.visibility_conditions' => 'nullable|json',
            'stages.*.sections.*.fields' => 'required|array',
            'stages.*.sections.*.fields.*.id' => 'nullable|integer|exists:fields,id',
            'stages.*.sections.*.fields.*.field_type_id' => 'required|integer|exists:field_types,id',
            'stages.*.sections.*.fields.*.label' => 'required|string|max:255',
            'stages.*.sections.*.fields.*.helper_text' => 'nullable|string',
            'stages.*.sections.*.fields.*.placeholder' => 'nullable|string|max:255',
            'stages.*.sections.*.fields.*.default_value' => 'nullable|string',
            'stages.*.sections.*.fields.*.visibility_conditions' => 'nullable|json',
        ];
    }

    public function messages(): array
    {
        return [
            'stages.required' => 'Stages are required.',
            'stages.array' => 'Stages must be an array.',
            'stages.*.id.integer' => 'Stage ID must be an integer.',
            'stages.*.id.exists' => 'One or more stages do not exist.',
            'stages.*.name.required' => 'Stage name is required.',
            'stages.*.name.string' => 'Stage name must be a string.',
            'stages.*.name.max' => 'Stage name cannot exceed 255 characters.',
            'stages.*.is_initial.required' => 'Stage initial flag is required.',
            'stages.*.is_initial.boolean' => 'Stage initial flag must be a boolean.',
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
        ];
    }
}
