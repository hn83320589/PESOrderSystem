<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): JsonResponse
    {
        $data = $request->validate(['months' => ['nullable', 'integer', 'min:1', 'max:24']]);

        return response()->json(['data' => $dashboard->build($data['months'] ?? 12)]);
    }
}
