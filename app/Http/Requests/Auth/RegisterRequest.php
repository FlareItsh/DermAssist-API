<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
            'firstName' => ['required', 'string', 'max:255'],
            'middleName' => ['nullable', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'affiliation' => ['nullable', 'string', 'max:255'],
            'role' => ['required', 'string', 'in:patient,doctor'],
            'prcNumber' => ['required_if:role,doctor', 'nullable', 'string', 'digits:7'],
            'idPhoto' => ['nullable', 'string'],
            'consent_dataset' => ['nullable', 'boolean'],
            'agree_to_terms' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('prcNumber') && is_string($this->prcNumber)) {
            $this->merge([
                'prcNumber' => preg_replace('/\D/', '', $this->prcNumber),
            ]);
        }
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prcNumber.digits' => 'The PRC license number must be exactly 7 digits.',
            'prcNumber.required_if' => 'The PRC license number is required for doctor registration.',
        ];
    }
}
