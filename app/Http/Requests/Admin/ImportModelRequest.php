<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ModelIdentityRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportModelRequest extends FormRequest
{
    use ModelIdentityRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:json', 'max:1024'],
            'slug' => $this->slugRules(required: false),
            'label' => $this->labelRules(required: false),
        ];
    }
}
