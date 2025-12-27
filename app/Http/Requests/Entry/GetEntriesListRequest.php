<?php

namespace App\Http\Requests\Entry;

use Illuminate\Foundation\Http\FormRequest;

class GetEntriesListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'form_version_id' => 'required|integer|exists:form_versions,id',
            'date_from' => 'sometimes|date',
            'date_to' => 'sometimes|date|after_or_equal:date_from',
            'field_filters' => 'sometimes|array',
            'is_considered' => 'sometimes|boolean',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'form_version_id.required' => 'Form version ID is required.',
            'form_version_id.integer' => 'Form version ID must be an integer.',
            'form_version_id.exists' => 'The selected form version does not exist.',
            'date_from.date' => 'Start date must be a valid date.',
            'date_to.date' => 'End date must be a valid date.',
            'date_to.after_or_equal' => 'End date must be after or equal to start date.',
            'field_filters.array' => 'Field filters must be an array.',
            'page.integer' => 'Page must be an integer.',
            'page.min' => 'Page must be at least 1.',
            'per_page.integer' => 'Per page must be an integer.',
            'per_page.min' => 'Per page must be at least 1.',
            'per_page.max' => 'Per page cannot exceed 100.',
            'is_considered.boolean' => 'Considered must be true or false.',
        ];
    }

    protected function prepareForValidation(): void
{
    if ($this->has('is_considered')) {
        $this->merge([
            'is_considered' => filter_var(
                $this->input('is_considered'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ),
        ]);
    }
}

}
