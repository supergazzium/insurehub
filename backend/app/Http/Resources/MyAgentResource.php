<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full agent view for the OWNING agent — includes decrypted PII but with
 * UI-friendly masking on the sensitive fields. The client can request the
 * unmasked value via a dedicated endpoint (`GET /me/agent/id-card-unmask`)
 * which writes an audit_entries row.
 *
 * @mixin Agent
 */
class MyAgentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'agentCode' => $this->agent_code ?? '',
            'signupType' => $this->signup_type ?? 'personal',
            'juristicName' => $this->juristic_name ?? '',
            'firstName' => $this->first_name ?? '',
            'lastName' => $this->last_name ?? '',
            'firstNameEn' => $this->first_name_en ?? '',
            'lastNameEn' => $this->last_name_en ?? '',
            'nickname' => $this->nickname ?? '',
            'gender' => $this->gender ?? '',
            'email' => $this->email ?? '',
            'phone' => $this->phone ?? '',
            'lineId' => $this->line_id ?? '',
            'facebookName' => $this->facebook_name ?? '',
            'birthDate' => $this->birth_date?->toDateString(),
            'joinedAt' => $this->joined_at?->toDateString(),

            // Approval workflow
            'approvalStatus' => $this->approval_status,
            'approvalNote' => $this->approval_note,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'rejectedAt' => $this->rejected_at?->toIso8601String(),

            // Photos — path is server-side; client fetches via signed URL later.
            'profilePhotoPath' => $this->profile_photo_path,
            'idCardPhotoPath' => $this->id_card_photo_path,
            'bankBookPhotoPath' => $this->bank_book_photo_path,

            // Masked PII — full values only on unmask endpoints.
            'idCardMasked' => self::maskId($this->id_card),
            'bankAccountNoMasked' => self::maskAcct($this->bank_account_no),
            'bankAccountName' => $this->bank_account_name ?? '',
            'bankId' => $this->bank_id !== null ? (string) $this->bank_id : null,
            'bankNameText' => $this->bank_name_text ?? '',

            // License toggles + numbers
            'licenseLifeNo' => $this->license_life_no ?? '',
            'licenseLifeExpiry' => $this->license_life_expiry?->toDateString(),
            'licenseNonLifeNo' => $this->license_non_life_no ?? '',
            'licenseNonLifeExpiry' => $this->license_non_life_expiry?->toDateString(),
            'licenseNumber' => $this->license_number ?? '',
            'licenseExpiry' => $this->license_expiry?->toDateString(),
            'hasLifeLicense' => (bool) ($this->has_life_license ?? false),
            'hasNonLifeLicense' => (bool) ($this->has_non_life_license ?? false),

            // Tax invoice address (primary — always required)
            'address' => $this->address ?? '',
            'subDistrict' => $this->sub_district ?? '',
            'district' => $this->district ?? '',
            'province' => $this->province ?? '',
            'postcode' => $this->postcode ?? '',

            // Document delivery address (secondary — may mirror tax address)
            'deliverySameAsTax' => (bool) ($this->delivery_same_as_tax ?? true),
            'deliveryAddress' => $this->delivery_address ?? '',
            'deliverySubDistrict' => $this->delivery_sub_district ?? '',
            'deliveryDistrict' => $this->delivery_district ?? '',
            'deliveryProvince' => $this->delivery_province ?? '',
            'deliveryPostcode' => $this->delivery_postcode ?? '',

            'active' => (bool) $this->active,
        ];
    }

    /**
     * Mask a national ID: keep the first digit and last digit, mask the rest.
     *   1234567890123 → 1-XXXX-XXXX-XX-3
     */
    private static function maskId(?string $id): string
    {
        if ($id === null || $id === '') {
            return '';
        }
        $len = strlen($id);
        if ($len <= 2) {
            return $id;
        }
        return $id[0].str_repeat('X', $len - 2).$id[$len - 1];
    }

    /**
     * Mask a bank account number: keep last 4 digits.
     *   123-4-56789-0 → XXX-X-XXXX9-0
     */
    private static function maskAcct(?string $acct): string
    {
        if ($acct === null || $acct === '') {
            return '';
        }
        $len = strlen($acct);
        if ($len <= 4) {
            return $acct;
        }
        $keep = substr($acct, -4);
        // Replace every alphanumeric in the prefix; keep separators like '-'.
        $prefix = preg_replace('/[A-Za-z0-9]/', 'X', substr($acct, 0, $len - 4));
        return $prefix.$keep;
    }
}
