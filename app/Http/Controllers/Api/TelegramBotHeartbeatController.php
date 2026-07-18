<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\TelegramBotHeartbeat;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

final class TelegramBotHeartbeatController extends Controller
{
    private const HEARTBEAT_KEY =
        'telegram:bot:heartbeat';

    public function store(): JsonResponse
    {
        $now = now();

        /*
        |--------------------------------------------------------------------------
        | Heartbeat expires after 30 seconds
        |--------------------------------------------------------------------------
        |
        | Bot sends a heartbeat every 10 seconds.
        |
        | If heartbeat stops:
        | Cache key automatically disappears after 30 seconds.
        |
        */
        Cache::put(
            self::HEARTBEAT_KEY,
            $now->toISOString(),
            now()->addSeconds(30)
        );

        /*
        |--------------------------------------------------------------------------
        | Broadcast online status immediately
        |--------------------------------------------------------------------------
        */
        TelegramBotHeartbeat::dispatch(
            $now->toISOString()
        );

        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'online',
                'heartbeat_at' => $now,
            ],
        ]);
    }

    public function show(): JsonResponse
    {
        $heartbeat = Cache::get(
            self::HEARTBEAT_KEY
        );

        return response()->json([
            'success' => true,

            'data' => [
                'status' => $heartbeat
                    ? 'online'
                    : 'offline',

                'heartbeat_at' => $heartbeat,
            ],
        ]);
    }
}