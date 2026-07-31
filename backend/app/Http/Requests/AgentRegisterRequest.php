<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\EmailVerificationToken;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

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
            // Names must be Thai characters. Rendered as U+0E00–U+0E7F class
            // so the rule matches Thai letters, tone marks, and digits with
            // ASCII whitespace between words.
            'firstName' => ['required', 'string', 'max:120', 'regex:/^[\x{0E00}-\x{0E7F}][\x{0E00}-\x{0E7F}\s]*$/u'],
            'lastName' => ['required', 'string', 'max:120', 'regex:/^[\x{0E00}-\x{0E7F}][\x{0E00}-\x{0E7F}\s]*$/u'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'idCard' => ['required', 'string', 'size:13', 'regex:/^\d{13}$/'],
            'birthDate' => ['required', 'date', 'before:today'],
            // Thai mobile: 10 digits, "0" + mobile prefix (6/8/9) + 8 digits.
            // Separators (space, dash, parens) are stripped in prepareForValidation().
            'phone' => ['required', 'string', 'regex:/^0[689]\d{8}$/'],
            'lineId' => ['sometimes', 'nullable', 'string', 'max:64'],
            'termsAccepted' => ['required', 'accepted'],
            // Juristic (business) name is required for corporate signups.
            // Thai or English is allowed — no character-set constraint.
            'juristicName' => [
                'required_if:signupType,corporate',
                'nullable',
                'string',
                'max:255',
            ],
            // Optional referral link token; validated by the controller.
            'referralToken' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Proof of email OTP verification. Issued by /auth/email-otp/verify.
            'emailOtpToken' => ['required', 'string', 'size:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'firstName.regex' => 'First name must be Thai characters only.',
            'lastName.regex' => 'Last name must be Thai characters only.',
            'idCard.size' => 'Thai ID must be exactly 13 digits.',
            'idCard.regex' => 'Thai ID must be 13 digits.',
            'phone.regex' => 'Phone must be a valid Thai mobile number (10 digits starting with 06, 08, or 09).',
            'emailOtpToken.required' => 'Email verification is required before submitting.',
            'emailOtpToken.size' => 'Email verification token is invalid.',
        ];
    }

    /**
     * Strip common visual separators from the phone number before validation
     * so users can type "08x-xxx-xxxx" or "08x xxx xxxx" and still pass.
     */
    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');
        if (is_string($phone)) {
            $this->merge(['phone' => preg_replace('/[\s\-()]/', '', $phone)]);
        }
    }

    /**
     * Add the MOD-11 Thai ID checksum after Laravel's own rules pass. This
     * covers both National ID (personal) and juristic-registration number
     * (corporate) — they share the same 13-digit checksum algorithm.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $id = (string) ($this->input('idCard') ?? '');
            if (preg_match('/^\d{13}$/', $id) === 1 && !self::isValidThaiId13($id)) {
                $v->errors()->add(
                    'idCard',
                    $this->input('signupType') === 'corporate'
                        ? 'Business registration number is not a valid 13-digit Thai ID.'
                        : 'National ID is not a valid 13-digit Thai ID.'
                );
            }

            // Verify the OTP token: must exist, unconsumed, unexpired, and
            // bound to the SAME email being registered (case-insensitive).
            $token = (string) ($this->input('emailOtpToken') ?? '');
            $email = strtolower(trim((string) ($this->input('email') ?? '')));
            if ($token === '' || $email === '') {
                return; // required-rule will flag missing token separately
            }
            $row = EmailVerificationToken::query()
                ->where('token', $token)
                ->first();
            if ($row === null
                || $row->consumed_at !== null
                || $row->expires_at->isPast()
                || strtolower($row->email) !== $email) {
                $v->errors()->add(
                    'emailOtpToken',
                    'Email verification is invalid or has expired. Please verify your email again.'
                );
            }
        });
    }

    private static function isValidThaiId13(string $digits): bool
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ((int) $digits[$i]) * (13 - $i);
        }
        $check = (11 - ($sum % 11)) % 10;
        return $check === (int) $digits[12];
    }
}
