<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\CarrierContactResource;
use App\Models\Carrier;
use App\Models\CarrierContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CarrierContactController extends ApiController
{
    public function index(Request $request, Carrier $carrier): AnonymousResourceCollection
    {
        $this->authorizeCarrier($request, $carrier);
        return CarrierContactResource::collection($carrier->contacts()->get());
    }

    public function store(Request $request, Carrier $carrier): JsonResponse
    {
        $this->authorizeCarrier($request, $carrier);
        $data = $this->validated($request, false);
        $data['tenant_id'] = (int) $carrier->tenant_id;
        $contact = $carrier->contacts()->create($data);
        $this->enforceSinglePrimary($carrier, $contact);
        return (new CarrierContactResource($contact->fresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, Carrier $carrier, CarrierContact $contact): CarrierContactResource
    {
        $this->authorizeCarrier($request, $carrier);
        $this->authorizeContact($carrier, $contact);
        $data = $this->validated($request, true);
        $contact->update($data);
        $this->enforceSinglePrimary($carrier, $contact);
        return new CarrierContactResource($contact->fresh());
    }

    public function destroy(Request $request, Carrier $carrier, CarrierContact $contact): JsonResponse
    {
        $this->authorizeCarrier($request, $carrier);
        $this->authorizeContact($carrier, $contact);
        $contact->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $isUpdate): array
    {
        $sometimes = $isUpdate ? 'sometimes' : 'nullable';
        $rules = [
            // #8 — firstName doubles as "ชื่อ / แผนก" (a person OR a department
            // like "Group motor"), so it accepts a longer free-text value.
            'firstName' => [$sometimes, 'string', 'max:255'],
            'lastName' => [$sometimes, 'string', 'max:120'],
            'phone' => [$sometimes, 'string', 'max:32'],
            // #8 — email accepts ONE address or a comma-separated list
            //   (e.g. "motor@aia.co.th, claims@aia.co.th"). Validate each part.
            'email' => [$sometimes, 'string', 'max:1000', function ($attribute, $value, $fail) {
                if ($value === null || $value === '') {
                    return;
                }
                foreach (explode(',', (string) $value) as $part) {
                    $part = trim($part);
                    if ($part === '') {
                        continue;
                    }
                    if (! filter_var($part, FILTER_VALIDATE_EMAIL)) {
                        $fail("อีเมล \"{$part}\" ไม่ถูกต้อง");
                    }
                }
            }],
            'isPrimary' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
        $v = $request->validate($rules);

        $map = [
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'phone' => 'phone',
            'email' => 'email',
            'isPrimary' => 'is_primary',
            'sortOrder' => 'sort_order',
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

    private function authorizeCarrier(Request $request, Carrier $carrier): void
    {
        if ((int) $carrier->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }

    private function authorizeContact(Carrier $carrier, CarrierContact $contact): void
    {
        if ((int) $contact->carrier_id !== (int) $carrier->id) {
            abort(404);
        }
    }

    private function enforceSinglePrimary(Carrier $carrier, CarrierContact $contact): void
    {
        if (! $contact->is_primary) return;
        $carrier->contacts()
            ->where('id', '<>', $contact->id)
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
    }
}
