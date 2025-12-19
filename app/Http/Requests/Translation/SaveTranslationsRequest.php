<?php

namespace App\Http\Requests\Translation;

use Illuminate\Foundation\Http\FormRequest;

class SaveTranslationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'form_version_id' => 'required|integer|exists:form_versions,id',
            'language_id' => 'required|integer|exists:languages,id',
            'form_name' => 'nullable|string|max:255',
            'field_translations' => 'required|array',
            'field_translations.*.field_id' => 'required|integer|exists:fields,id',
            'field_translations.*.label' => 'nullable|string|max:255',
            'field_translations.*.helper_text' => 'nullable|string',
            'field_translations.*.default_value' => 'nullable|string',
            'field_translations.*.placeholder' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'form_version_id.required' => 'Form version ID is required.',
            'form_version_id.integer' => 'Form version ID must be an integer.',
            'form_version_id.exists' => 'The selected form version does not exist.',
            'language_id.required' => 'Language ID is required.',
            'language_id.integer' => 'Language ID must be an integer.',
            'language_id.exists' => 'The selected language does not exist.',
            'form_name.string' => 'Form name must be a string.',
            'form_name.max' => 'Form name cannot exceed 255 characters.',
            'field_translations.required' => 'Field translations are required.',
            'field_translations.array' => 'Field translations must be an array.',
            'field_translations.*.field_id.required' => 'Field ID is required for each translation.',
            'field_translations.*.field_id.integer' => 'Field ID must be an integer.',
            'field_translations.*.field_id.exists' => 'One or more fields do not exist.',
            'field_translations.*.label.string' => 'Field label must be a string.',
            'field_translations.*.label.max' => 'Field label cannot exceed 255 characters.',
            'field_translations.*.helper_text.string' => 'Helper text must be a string.',
            'field_translations.*.default_value.string' => 'Default value must be a string.',
        ];
    }
}
