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
            'service_name' => 'required|string',
            'description' => 'string',
            'required_skills' => 'nullable|json',
            'hours_cost' => 'nullable|numeric|regex:/^\d+(\.\d{1,2})?$/',
        ];
    }
}
