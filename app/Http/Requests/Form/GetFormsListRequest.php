<?php

namespace App\Http\Requests\Form;

use Illuminate\Foundation\Http\FormRequest;

class GetFormsListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|in:all,drafted,published,archived',
            'category_filter_type' => 'nullable|in:all,specific,group,without',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
            'sort_by' => 'nullable|in:latest_submission,publish_time,creation_time',
            'sort_direction' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'name.string' => 'Form name must be a string.',
            'name.max' => 'Form name cannot exceed 255 characters.',
            'date_from.date' => 'Start date must be a valid date.',
            'date_to.date' => 'End date must be a valid date.',
            'date_to.after_or_equal' => 'End date must be after or equal to start date.',
            'status.in' => 'Status must be one of: all, drafted, published, archived.',
            'category_filter_type.in' => 'Category filter type must be one of: all, specific, group, without.',
            'category_ids.array' => 'Category IDs must be an array.',
            'category_ids.*.integer' => 'Each category ID must be an integer.',
            'category_ids.*.exists' => 'One or more selected categories do not exist.',
            'sort_by.in' => 'Sort by must be one of: latest_submission, publish_time, creation_time.',
            'sort_direction.in' => 'Sort direction must be asc or desc.',
            'page.integer' => 'Page must be an integer.',
            'page.min' => 'Page must be at least 1.',
            'per_page.integer' => 'Per page must be an integer.',
            'per_page.min' => 'Per page must be at least 1.',
            'per_page.max' => 'Per page cannot exceed 100.',
        ];
    }
}
