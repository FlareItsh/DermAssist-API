<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

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
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'firstName' => is_string($this->firstName) ? trim(strip_tags($this->firstName)) : $this->firstName,
            'middleName' => is_string($this->middleName) ? trim(strip_tags($this->middleName)) : $this->middleName,
            'lastName' => is_string($this->lastName) ? trim(strip_tags($this->lastName)) : $this->lastName,
            'email' => is_string($this->email) ? trim(strtolower($this->email)) : $this->email,
            'affiliation' => is_string($this->affiliation) ? trim(strip_tags($this->affiliation)) : $this->affiliation,
            'prcNumber' => is_string($this->prcNumber) ? preg_replace('/\D/', '', $this->prcNumber) : $this->prcNumber,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'firstName' => ['required', 'string', 'min:2', 'max:50', "regex:/^[\\pL\\s\\-'.]+$/u"],
            'middleName' => ['nullable', 'string', 'max:50', "regex:/^[\\pL\\s\\-'.]+$/u"],
            'lastName' => ['required', 'string', 'min:2', 'max:50', "regex:/^[\\pL\\s\\-'.]+$/u"],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
                'confirmed',
            ],
            'affiliation' => ['nullable', 'string', 'max:255'],
            'role' => ['required', 'string', 'in:patient,doctor'],
            'prcNumber' => ['required_if:role,doctor', 'nullable', 'string', 'digits:7'],
            'idPhoto' => ['required_if:role,doctor', 'nullable', 'string'],
            'consent_dataset' => ['nullable', 'boolean'],
            'agree_to_terms' => ['required', 'accepted'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'firstName.required' => 'First name is required.',
            'firstName.min' => 'First name must be at least 2 characters.',
            'firstName.max' => 'First name may not exceed 50 characters.',
            'firstName.regex' => 'First name may only contain letters, spaces, hyphens, and apostrophes.',
            'middleName.max' => 'Middle name may not exceed 50 characters.',
            'middleName.regex' => 'Middle name may only contain letters, spaces, hyphens, and apostrophes.',
            'lastName.required' => 'Last name is required.',
            'lastName.min' => 'Last name must be at least 2 characters.',
            'lastName.max' => 'Last name may not exceed 50 characters.',
            'lastName.regex' => 'Last name may only contain letters, spaces, hyphens, and apostrophes.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.unique' => 'This email address is already registered.',
            'password.required' => 'Password is required.',
            'password.confirmed' => 'Password confirmation does not match.',
            'prcNumber.digits' => 'The PRC license number must be exactly 7 digits.',
            'prcNumber.required_if' => 'The PRC license number is required for doctor registration.',
            'idPhoto.required_if' => 'A photo of your PRC license ID is required for doctor registration.',
            'agree_to_terms.required' => 'You must agree to the Terms and Conditions and Privacy Policy.',
            'agree_to_terms.accepted' => 'You must agree to the Terms and Conditions and Privacy Policy.',
        ];
    }
}
