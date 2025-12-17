<?php

namespace App\Http\Requests\EndUser;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use App\Models\Entry;

class SubmitLaterStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'public_identifier' => 'required|string|exists:entries,public_identifier',
            'stage_transition_id' => 'required|integer|exists:stage_transitions,id',
            'field_values' => 'required|array',
            'field_values.*' => 'nullable', // Values can be any type
        ];
    }

    public function messages(): array
    {
        return [
            'public_identifier.required' => 'Public identifier is required.',
            'public_identifier.string' => 'Public identifier must be a string.',
            'public_identifier.exists' => 'Entry not found.',
            'stage_transition_id.required' => 'Stage transition ID is required.',
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
            $publicIdentifier = $this->input('public_identifier');
            
            if ($publicIdentifier) {
                $entry = Entry::with('formVersion')
                    ->where('public_identifier', $publicIdentifier)
                    ->first();
                
                if ($entry && $entry->formVersion && $entry->formVersion->status !== 'published') {
                    $validator->errors()->add(
                        'public_identifier',
                        'Only entries from published form versions can be updated.'
                    );
                }
            }
        });
    }
}
