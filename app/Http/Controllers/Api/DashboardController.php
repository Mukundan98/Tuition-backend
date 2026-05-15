<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\DashboardStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function admin(DashboardStatsService $stats): JsonResponse
    {
        return ApiResponse::success($stats->admin());
    }

    public function teacher(Request $request, DashboardStatsService $stats): JsonResponse
    {
        $user = $request->user();
        $user?->load(['teacherProfile']);

        return ApiResponse::success($stats->teacher($user));
    }

    public function student(Request $request, DashboardStatsService $stats): JsonResponse
    {
        $user = $request->user();
        $user?->load(['studentProfile']);

        return ApiResponse::success($stats->student($user));
    }

    public function parent(Request $request, DashboardStatsService $stats): JsonResponse
    {
        return ApiResponse::success($stats->parent($request->user()));
    }
}
