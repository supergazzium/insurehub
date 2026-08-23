<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Bank;
use App\Models\Location;
use App\Models\MotorMarketGroup;
use App\Models\MotorVehicle;
use App\Models\NamePrefix;
use App\Models\Nationality;
use App\Models\Occupation;
use App\Models\PaymentMethod;
use App\Models\PolicyStatusLookup;
use App\Models\Religion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only lookup tables — shared across all tenants. */
class LookupController extends ApiController
{
    public function banks(): JsonResponse
    {
        return response()->json(['data' => Bank::orderBy('name_th')->get()->map(fn ($b) => [
            'id' => (string) $b->id,
            'nameTh' => $b->name_th,
            'nameEn' => $b->name_en,
        ])]);
    }

    public function nationalities(): JsonResponse
    {
        return response()->json(['data' => Nationality::orderBy('nation_name_th')->get()->map(fn ($n) => [
            'id' => (string) $n->id,
            'iso2' => $n->iso2,
            'iso3' => $n->iso3,
            'nameTh' => $n->nation_name_th,
            'nameEn' => $n->nation_name_en,
        ])]);
    }

    public function religions(): JsonResponse
    {
        return response()->json(['data' => Religion::orderBy('id')->get()->map(fn ($r) => [
            'id' => (string) $r->id,
            'nameTh' => $r->name_th,
            'nameEn' => $r->name_en,
        ])]);
    }

    public function occupations(): JsonResponse
    {
        return response()->json(['data' => Occupation::orderBy('name_th')->get()->map(fn ($o) => [
            'id' => (string) $o->id,
            'code' => $o->access_code,
            'type' => $o->type,
            'nameTh' => $o->name_th,
            'nameEn' => $o->name_en,
        ])]);
    }

    public function prefixes(): JsonResponse
    {
        return response()->json(['data' => NamePrefix::orderBy('insured_type_id')->orderBy('title_code')->get()->map(fn ($p) => [
            'id' => (string) $p->id,
            'insuredType' => $p->insured_type,
            'descriptionTh' => $p->description_th,
            'descriptionEn' => $p->description_en,
        ])]);
    }

    public function locations(Request $request): JsonResponse
    {
        $q = Location::query();
        if ($zip = $request->input('zip')) {
            $q->where('zip', $zip);
        }
        if ($province = $request->input('province')) {
            $q->where('province', 'like', "%{$province}%");
        }
        return response()->json([
            'data' => $q->limit(50)->get()->map(fn ($l) => [
                'id' => (string) $l->id,
                'province' => $l->province,
                'amphur' => $l->amphur,
                'district' => $l->district,
                'zip' => $l->zip,
            ]),
        ]);
    }

    public function policyStatuses(): JsonResponse
    {
        return response()->json(['data' => PolicyStatusLookup::orderBy('id')->get()->map(fn ($s) => [
            'id' => (string) $s->id,
            'nameTh' => $s->name_th,
            'groupNameTh' => $s->group_name_th,
            'code' => $s->code,
        ])]);
    }

    public function paymentMethods(): JsonResponse
    {
        return response()->json(['data' => PaymentMethod::orderBy('id')->get()->map(fn ($m) => [
            'id' => (string) $m->id,
            'nameTh' => $m->name_th,
            'code' => $m->code,
        ])]);
    }

    public function motorVehicles(Request $request): JsonResponse
    {
        $q = MotorVehicle::query();
        if ($brand = $request->input('brand')) {
            $q->where('vehicle_brand', 'like', "%{$brand}%");
        }
        if ($model = $request->input('model')) {
            $q->where('vehicle_model', 'like', "%{$model}%");
        }
        return response()->json([
            'data' => $q->limit(50)->get()->map(fn ($v) => [
                'id' => (string) $v->id,
                'brand' => $v->vehicle_brand,
                'model' => $v->vehicle_model,
                'submodel' => $v->vehicle_submodel,
                'yearBeg' => $v->vh_year_beg,
                'yearEnd' => $v->vh_year_end,
                'redbookCode' => $v->redbook_code,
            ]),
        ]);
    }

    /** Distinct vehicle brand typeahead — powers the motor risk-schema
     *  `remote_select` for ยี่ห้อรถ. Reuses motor_vehicles as the source
     *  of truth so no separate brand table needs to be maintained.
     *  `q` filters case-insensitively; returns up to 50 unique brand
     *  strings ordered alphabetically. */
    public function vehicleBrands(Request $request): JsonResponse
    {
        $q = MotorVehicle::query()
            ->select('vehicle_brand')
            ->whereNotNull('vehicle_brand')
            ->where('vehicle_brand', '!=', '');

        if ($needle = $request->input('q')) {
            $q->where('vehicle_brand', 'like', "%{$needle}%");
        }

        $brands = $q->distinct()->orderBy('vehicle_brand')->limit(50)->pluck('vehicle_brand');

        return response()->json([
            'data' => $brands->values()->map(fn (string $b) => [
                'id' => $b,
                'label' => $b,
            ]),
        ]);
    }

    /** Distinct vehicle model typeahead, scoped to a brand. Powers the
     *  cascade `remote_select` — model options refresh whenever the
     *  brand changes. Empty brand → 400 so the frontend surfaces the
     *  "pick a brand first" state instead of showing all 32k models. */
    public function vehicleModels(Request $request): JsonResponse
    {
        $brand = trim((string) $request->input('brand', ''));
        if ($brand === '') {
            return response()->json(['data' => []]);
        }

        $q = MotorVehicle::query()
            ->select('vehicle_model')
            ->where('vehicle_brand', $brand)
            ->whereNotNull('vehicle_model')
            ->where('vehicle_model', '!=', '');

        if ($needle = $request->input('q')) {
            $q->where('vehicle_model', 'like', "%{$needle}%");
        }

        $models = $q->distinct()->orderBy('vehicle_model')->limit(100)->pluck('vehicle_model');

        return response()->json([
            'data' => $models->values()->map(fn (string $m) => [
                'id' => $m,
                'label' => $m,
            ]),
        ]);
    }

    /** The 10 canonical Thai motor market categories (รถหรู / รถตลาดทั่วไป /
     *  รถไฮซัม / รถบัส / รถมอเตอร์ไซค์ / รถกระบะ 2 ประตู / รถบรรทุก /
     *  รถกระบะ 4 ประตู / รถหางพ่วง / รถอื่นๆ). Powers the motor risk-schema
     *  `remote_select` for ประเภทรถ. Response key is `id` = group_code so
     *  the value persisted in policies.risk_data is the stable 2-char code
     *  regardless of Thai/English label drift. */
    public function motorMarketGroups(): JsonResponse
    {
        return response()->json([
            'data' => MotorMarketGroup::query()
                ->orderBy('group_code')
                ->get()
                ->map(fn (MotorMarketGroup $g) => [
                    'id' => $g->group_code,
                    'label' => $g->desc_th,
                    'labelEn' => $g->desc_en,
                ]),
        ]);
    }
}
