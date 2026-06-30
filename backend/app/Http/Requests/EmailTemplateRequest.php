<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            'label' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:255'],
            'desc' => ['sometimes', 'nullable', 'string'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:64'],
            'department' => [$isCreate ? 'required' : 'sometimes', 'string', 'in:new_business,underwriting,policy_issue,claims,other'],
            'subject' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:512'],
            'body' => [$isCreate ? 'required' : 'sometimes', 'string'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
