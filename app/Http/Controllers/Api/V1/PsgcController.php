<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Psgc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public lists for the address pickers (shop application, branches).
 * Read-only PSGC data, cached on the server and in the browser.
 */
class PsgcController extends Controller
{
    public function provinces(): JsonResponse
    {
        return $this->ok(Psgc::provinces());
    }

    public function cities(Request $request): JsonResponse
    {
        $v = $request->validate(['province' => ['required', 'string', 'max:10']]);

        return $this->ok(Psgc::cities($v['province']));
    }

    public function districts(Request $request): JsonResponse
    {
        $v = $request->validate(['city' => ['required', 'string', 'max:10']]);

        return $this->ok(Psgc::districts($v['city']), ['district_required' => Psgc::districtRequired($v['city'])]);
    }

    public function barangays(Request $request): JsonResponse
    {
        $v = $request->validate([
            'city' => ['required', 'string', 'max:10'],
            'district' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->ok(Psgc::barangays($v['city'], $v['district'] ?? null));
    }

    private function ok(array $items, array $extra = []): JsonResponse
    {
        return response()
            ->json(['success' => true, 'data' => $items] + $extra)
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
