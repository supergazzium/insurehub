<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AgentRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'signupType' => ['required', 'in:personal,corporate'],
            'firstName' => ['required', 'string', 'max:120'],
            'lastName' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'idCard' => ['required', 'string', 'min:5', 'max:32'],
            'birthDate' => ['required', 'date', 'before:today'],
            'phone' => ['required', 'string', 'max:32'],
            'lineId' => ['sometimes', 'nullable', 'string', 'max:64'],
            'termsAccepted' => ['required', 'accepted'],
            // Only required for corporate signups; a juristic name is the
            // "business registration name" from the spec.
            'juristicName' => ['required_if:signupType,corporate', 'nullable', 'string', 'max:255'],
            // Optional referral link token; validated by the controller.
            'referralToken' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
