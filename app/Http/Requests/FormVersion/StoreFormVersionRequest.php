<?php

namespace App\Http\Requests\FormVersion;

use Illuminate\Foundation\Http\FormRequest;

class StoreFormVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'copy_from_current' => 'required|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'copy_from_current.required' => 'Copy from current flag is required.',
            'copy_from_current.boolean' => 'Copy from current must be a boolean.',
        ];
    }
}
