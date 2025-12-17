<?php

namespace App\Http\Requests\EndUser;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use App\Models\FormVersion;

class SubmitInitialStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'form_version_id' => 'required|integer|exists:form_versions,id',
            'stage_transition_id' => 'nullable|integer|exists:stage_transitions,id',
            'field_values' => 'required|array',
            'field_values.*' => 'nullable', // Values can be any type
        ];
    }

    public function messages(): array
    {
        return [
            'form_version_id.required' => 'Form version ID is required.',
            'form_version_id.integer' => 'Form version ID must be an integer.',
            'form_version_id.exists' => 'The selected form version does not exist.',
            'stage_transition_id.integer' => 'Stage transition ID must be an integer.',
            'stage_transition_id.exists' => 'The selected stage transition does not exist.',
            'field_values.required' => 'Field values are required.',
            'field_values.array' => 'Field values must be an array.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $formVersionId = $this->input('form_version_id');
            
            if ($formVersionId) {
                $formVersion = FormVersion::find($formVersionId);
                
                if ($formVersion && $formVersion->status !== 'published') {
                    $validator->errors()->add(
                        'form_version_id',
                        'Only published form versions can accept submissions.'
                    );
                }
            }
        });
    }
}
