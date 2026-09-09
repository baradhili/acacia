<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ServiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'hourly_rate' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,8}(\.\d{1,4})?$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'hourly_rate.regex' => 'The hourly rate may have up to 4 decimal places.',
        ];
    }
}
