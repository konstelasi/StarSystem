<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = $this->profileRules($this->user()->id);

        // A new address asks for the password, since it decides where a
        // password reset link goes. Typing the address already held doesn't.
        if (mb_strtolower((string) $this->input('email')) !== mb_strtolower($this->user()->email)) {
            $rules['current_password'] = ['required', 'string', 'current_password'];
        }

        return $rules;
    }
}
