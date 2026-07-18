<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemStatusService;
use Illuminate\Http\JsonResponse;

class SystemStatusController extends Controller
{
    public function __construct(
        private readonly SystemStatusService $statusService
    ) {}

    /*
    |--------------------------------------------------------------------------
    | GET /api/system/status
    |--------------------------------------------------------------------------
    | Snapshot endpoint — the frontend calls this once for instant first
    | paint, then live updates arrive over Reverb (private-system.status).
    */
    public function status(): JsonResponse
    {
        return response()->json($this->statusService->buildStatusPayload());
    }
}