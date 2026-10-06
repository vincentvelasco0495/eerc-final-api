<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LmsCatalogService;
use App\Support\HttpCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class LmsMetaController extends Controller
{
    public function show(LmsCatalogService $catalog): JsonResponse|Response
    {
        return HttpCache::json($catalog->meta(), 60, true);
    }
}
