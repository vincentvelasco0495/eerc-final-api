<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LmsCatalogService;
use Illuminate\Http\JsonResponse;

class LmsMetaController extends Controller
{
    public function show(LmsCatalogService $catalog): JsonResponse
    {
        return response()->json($catalog->meta());
    }
}
