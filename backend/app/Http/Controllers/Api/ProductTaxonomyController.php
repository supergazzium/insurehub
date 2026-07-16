<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductTaxonomyController extends ApiController
{
    /**
     * GET /product-categories
     *
     * Returns the full taxonomy (~28 rows), one row per
     * (group, category, subcategory) triple. Frontend filters client-side
     * because the dataset is tiny and won't grow beyond a few dozen rows.
     */
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('product_taxonomy')
            ->where('active', true)
            ->orderBy('sort_order')
            ->get(['group_', 'category', 'subcategory']);

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'group' => $r->group_,
                'category' => $r->category,
                'subcategory' => $r->subcategory,
            ]),
        ]);
    }
}
