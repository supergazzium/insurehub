<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isCreate = $this->isMethod('post');
        $id = $this->route('contract')?->id;
        $tenantId = $this->user()->tenant_id;

        return [
            'contractNo' => [
                $isCreate ? 'required' : 'sometimes',
                'string', 'max:64',
                Rule::unique('contracts', 'contract_no')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'carrierId' => [$isCreate ? 'required' : 'sometimes', Rule::exists('carriers', 'id')->where('tenant_id', $tenantId)],
            'effectiveFrom' => [$isCreate ? 'required' : 'sometimes', 'date'],
            'effectiveTo' => ['sometimes', 'nullable', 'date', 'after_or_equal:effectiveFrom'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
            'schedule' => ['sometimes', 'array'],
            'schedule.*.productId' => ['required_with:schedule', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'schedule.*.firstYearRate' => ['required_with:schedule', 'numeric', 'min:0'],
            'schedule.*.renewalRate' => ['required_with:schedule', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, mixed> */
    public function toModel(): array
    {
        $v = $this->validated();
        $map = [
            'contractNo' => 'contract_no',
            'carrierId' => 'carrier_id',
            'effectiveFrom' => 'effective_from',
            'effectiveTo' => 'effective_to',
            'notes' => 'notes',
            'active' => 'active',
        ];
        $out = [];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $v)) {
                $out[$snake] = $v[$camel];
            }
        }
        return $out;
    }

    /** @return list<array{product_id:int, first_year_rate:float, renewal_rate:float}> */
    public function scheduleRows(): array
    {
        $rows = $this->validated()['schedule'] ?? [];
        return array_map(static fn (array $r) => [
            'product_id' => (int) $r['productId'],
            'first_year_rate' => (float) $r['firstYearRate'],
            'renewal_rate' => (float) $r['renewalRate'],
        ], $rows);
    }
}
