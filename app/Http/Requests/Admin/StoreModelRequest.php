<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ModelIdentityRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreModelRequest extends FormRequest
{
    use ModelIdentityRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'slug' => $this->slugRules(),
            'label' => $this->labelRules(),
            'group' => $this->groupRules(),
        ];
    }
}
