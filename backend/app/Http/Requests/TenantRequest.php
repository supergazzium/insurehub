<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'nameEn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'taxId' => ['sometimes', 'nullable', 'string', 'max:20'],
            'oicLicense' => ['sometimes', 'nullable', 'string', 'max:32'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'district' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amphoe' => ['sometimes', 'nullable', 'string', 'max:255'],
            'province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postcode' => ['sometimes', 'nullable', 'string', 'max:16'],
            'commissionMode' => ['sometimes', 'string', 'in:asEarned,advance'],
            'payout.cycle' => ['sometimes', 'string', 'in:weekly,biweekly,monthly'],
            'payout.minBalance' => ['sometimes', 'numeric', 'min:0'],
            'payout.autoApprove' => ['sometimes', 'boolean'],
            'brandColor' => ['sometimes', 'string', 'max:16'],
            'emailSignature' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /** @return array<string, mixed> */
    public function toModel(): array
    {
        $v = $this->validated();
        $map = [
            'name' => 'name',
            'nameEn' => 'name_en',
            'taxId' => 'tax_id',
            'oicLicense' => 'oic_license',
            'phone' => 'phone',
            'email' => 'email',
            'website' => 'website',
            'address' => 'address',
            'district' => 'district',
            'amphoe' => 'amphoe',
            'province' => 'province',
            'postcode' => 'postcode',
            'commissionMode' => 'commission_mode',
            'brandColor' => 'brand_color',
            'emailSignature' => 'email_signature',
        ];
        $out = [];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $v)) {
                $out[$snake] = $v[$camel];
            }
        }
        if (array_key_exists('payout', $v)) {
            $p = $v['payout'];
            if (array_key_exists('cycle', $p)) {
                $out['payout_cycle'] = $p['cycle'];
            }
            if (array_key_exists('minBalance', $p)) {
                $out['payout_min_balance'] = $p['minBalance'];
            }
            if (array_key_exists('autoApprove', $p)) {
                $out['payout_auto_approve'] = $p['autoApprove'];
            }
        }
        return $out;
    }
}
