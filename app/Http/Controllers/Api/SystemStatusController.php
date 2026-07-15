<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/*
|--------------------------------------------------------------------------
| GET /api/system/status
|--------------------------------------------------------------------------
| Powers the "System Overview" dashboard widget:
|
|   API Server    → this request succeeded + DB reachable
|   Telegram Bot  → getMe API call (cached 60s)
|   Queue Worker  → heartbeat freshness (QueueHeartbeatJob every minute)
|   Redis Cache   → PING roundtrip
|
| Response:
| {
|   "success": true,
|   "overall": "ok" | "degraded",
|   "services": {
|     "api":    { "status": "online",    "label": "API Server" },
|     "bot":    { "status": "running",   "label": "Telegram Bot" },
|     "queue":  { "status": "active",    "label": "Queue Worker" },
|     "redis":  { "status": "connected", "label": "Redis Cache" }
|   },
|   "checked_at": "2026-07-11T10:00:00+07:00"
| }
*/
class SystemStatusController extends Controller
{
    /** Queue heartbeat older than this = worker considered down (seconds). */
    private const QUEUE_STALE_AFTER = 180; // 3 min (heartbeat runs every 1 min)

    /** Telegram getMe result cache (seconds) — don't hammer the Bot API. */
    private const TTL_BOT_CHECK = 60;

    public const QUEUE_HEARTBEAT_KEY = 'health:queue_heartbeat';

    public function __construct(
        protected TelegramBotService $telegram,
    ) {}

    public function status(): JsonResponse
    {
        $services = [
            'api'   => $this->checkApi(),
            'bot'   => $this->checkTelegramBot(),
            'queue' => $this->checkQueueWorker(),
            'redis' => $this->checkRedis(),
        ];

        $allOk = collect($services)->every(fn (array $s) => $s['ok']);

        return response()->json([
            'success'    => true,
            'overall'    => $allOk ? 'ok' : 'degraded',
            'services'   => $services,
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | API Server — if this code runs, HTTP works; also verify DB
    |--------------------------------------------------------------------------
    */
    private function checkApi(): array
    {
        try {
            DB::select('SELECT 1');

            return $this->result('api', 'API Server', true, 'online');
        } catch (Throwable $e) {
            Log::error('Health: DB check failed', ['error' => $e->getMessage()]);

            return $this->result('api', 'API Server', false, 'database_error');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Telegram Bot — getMe roundtrip, cached 60s
    |--------------------------------------------------------------------------
    */
    private function checkTelegramBot(): array
    {
        try {
            $ok = Cache::remember(
                'health:telegram_bot',
                self::TTL_BOT_CHECK,
                function (): bool {
                    $info = $this->telegram->webhookInfo(); // ← ADJUST: or a getMe() method if you have one

                    return (bool) ($info['ok'] ?? false);
                }
            );

            return $this->result('bot', 'Telegram Bot', $ok, $ok ? 'running' : 'unreachable');
        } catch (Throwable $e) {
            Log::error('Health: Telegram check failed', ['error' => $e->getMessage()]);

            return $this->result('bot', 'Telegram Bot', false, 'error');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Queue Worker — heartbeat freshness
    |--------------------------------------------------------------------------
    | QueueHeartbeatJob is dispatched every minute by the scheduler and,
    | when a worker processes it, writes the current timestamp here.
    | Fresh timestamp  → a worker is actually pulling jobs.
    | Stale/missing    → laravel-worker is down (or Supervisor stopped).
    */
    private function checkQueueWorker(): array
    {
        try {
            $lastBeat = Cache::get(self::QUEUE_HEARTBEAT_KEY);

            if ($lastBeat === null) {
                return $this->result('queue', 'Queue Worker', false, 'no_heartbeat');
            }

            $age = Carbon::parse($lastBeat)->diffInSeconds(now());

            $ok = $age <= self::QUEUE_STALE_AFTER;

            return $this->result(
                'queue',
                'Queue Worker',
                $ok,
                $ok ? 'active' : 'stale',
                ['last_heartbeat' => (string) $lastBeat, 'age_seconds' => (int) $age]
            );
        } catch (Throwable $e) {
            Log::error('Health: queue check failed', ['error' => $e->getMessage()]);

            return $this->result('queue', 'Queue Worker', false, 'error');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Redis — direct PING
    |--------------------------------------------------------------------------
    */
    private function checkRedis(): array
    {
        try {
            $pong = Redis::connection('default')->ping(); // ← ADJUST if you use 'cache'
    
            // phpredis returns true|'PONG'|'+PONG'; Predis returns a Status object
            // whose (string) value is 'PONG' — normalize everything to a string.
            $ok = $pong === true
                || strtoupper(trim((string) $pong, '+')) === 'PONG';
    
            return $this->result('redis', 'Redis Cache', $ok, $ok ? 'connected' : 'unexpected_reply');
        } catch (Throwable $e) {
            Log::error('Health: Redis check failed', ['error' => $e->getMessage()]);
    
            return $this->result('redis', 'Redis Cache', false, 'disconnected');
        }
    }
    
    private function result(string $key, string $label, bool $ok, string $status, array $extra = []): array
    {
        return array_merge([
            'key'    => $key,
            'label'  => $label,
            'ok'     => $ok,
            'status' => $status,
        ], $extra);
    }
}