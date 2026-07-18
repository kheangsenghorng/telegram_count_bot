<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TelegramGroup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class SystemStatusService
{
    /*
    |--------------------------------------------------------------------------
    | Heartbeat freshness thresholds (seconds)
    |--------------------------------------------------------------------------
    |
    | Listener writes every ~30s:
    | Offline after 90 seconds without heartbeat.
    |
    | Queue and scheduler:
    | Offline after 150 seconds without heartbeat.
    |
    */
    private const MAX_AGE_LISTENER = 90;

    private const MAX_AGE_QUEUE = 150;

    private const MAX_AGE_SCHEDULER = 150;

    /*
    |--------------------------------------------------------------------------
    | Group activity threshold
    |--------------------------------------------------------------------------
    |
    | A connected group is considered active when activity was received
    | within the last 2 minutes.
    |
    */
    private const MAX_AGE_GROUP_ACTIVITY = 120;

    /*
    |--------------------------------------------------------------------------
    | Heartbeat cache keys
    |--------------------------------------------------------------------------
    */
    private const KEY_LISTENER = 'heartbeat:telegram_listener';

    private const KEY_QUEUE = 'heartbeat:queue_worker';

    private const KEY_SCHEDULER = 'heartbeat:scheduler';

    /*
    |--------------------------------------------------------------------------
    | Full system status
    |--------------------------------------------------------------------------
    */
    public function buildStatusPayload(): array
    {
        $services = [
            'api' => $this->checkApi(),

            'database' =>
                $this->checkDatabase(),

            'redis' =>
                $this->checkRedis(),

            'bot' =>
                $this->checkTelegramBot(),

            'queue' =>
                $this->checkQueueWorker(),

            'scheduler' =>
                $this->checkScheduler(),
        ];

        $allOk = collect($services)->every(
            fn (array $service): bool =>
                $service['ok'] === true
        );

        return [
            'success' => true,

            'overall' =>
                $allOk
                    ? 'ok'
                    : 'degraded',

            'services' =>
                $services,

            'checked_at' =>
                now()->toIso8601String(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | API Server
    |--------------------------------------------------------------------------
    */
    private function checkApi(): array
    {
        return $this->result(
            key: 'api',
            label: 'API Server',
            ok: true,
            status: 'online',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */
    private function checkDatabase(): array
    {
        try {
            DB::select('SELECT 1');

            return $this->result(
                key: 'database',
                label: 'Database',
                ok: true,
                status: 'connected',
            );
        } catch (Throwable $e) {
            Log::error(
                'Health: Database check failed',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return $this->result(
                key: 'database',
                label: 'Database',
                ok: false,
                status: 'disconnected',
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Redis
    |--------------------------------------------------------------------------
    */
    private function checkRedis(): array
    {
        try {
            Redis::connection()->ping();

            return $this->result(
                key: 'redis',
                label: 'Redis Cache',
                ok: true,
                status: 'connected',
            );
        } catch (Throwable $e) {
            Log::error(
                'Health: Redis check failed',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return $this->result(
                key: 'redis',
                label: 'Redis Cache',
                ok: false,
                status: 'disconnected',
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Telegram Bot + Group Status
    |--------------------------------------------------------------------------
    |
    | Important:
    |
    | connection status:
    | - Determined by global Telegram listener heartbeat.
    |
    | activity status:
    | - Determined by each group's last_activity_at.
    |
    */
    private function checkTelegramBot(): array
    {
        $beat = $this->heartbeatFresh(
            self::KEY_LISTENER,
            self::MAX_AGE_LISTENER
        );

        $groupSummary = $this->getTelegramGroupSummary(
            $beat['ok']
        );

        return $this->result(
            key: 'bot',

            label: 'Telegram Bot',

            ok: $beat['ok'],

            status:
                $beat['ok']
                    ? 'running'
                    : 'stopped',

            extra: [
                'last_heartbeat' =>
                    $beat['last_at'],

                'heartbeat_age_seconds' =>
                    $beat['age_seconds'],

                'heartbeat_timeout_seconds' =>
                    self::MAX_AGE_LISTENER,

                'groups' =>
                    $groupSummary,
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Telegram Group Summary
    |--------------------------------------------------------------------------
    */
    private function getTelegramGroupSummary(
        bool $listenerOnline
    ): array {
        try {
            /*
            |--------------------------------------------------------------------------
            | Only groups currently connected to the service
            |--------------------------------------------------------------------------
            */
            $connectedQuery = TelegramGroup::query()
                ->where(
                    'status',
                    'connected'
                );

            $totalConnected = (clone $connectedQuery)
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Online / Offline
            |--------------------------------------------------------------------------
            |
            | We use the global listener heartbeat here.
            |
            | Listener online:
            | All connected groups are reachable by our listener.
            |
            | Listener offline:
            | All connected groups are considered unavailable.
            |
            */
            $onlineGroups = $listenerOnline
                ? $totalConnected
                : 0;

            $offlineGroups = $listenerOnline
                ? 0
                : $totalConnected;

            /*
            |--------------------------------------------------------------------------
            | Active Groups
            |--------------------------------------------------------------------------
            |
            | Active means activity received in the last 120 seconds.
            |
            */
            $activeSince = now()->subSeconds(
                self::MAX_AGE_GROUP_ACTIVITY
            );

            $activeGroups = (clone $connectedQuery)
                ->whereNotNull(
                    'last_activity_at'
                )
                ->where(
                    'last_activity_at',
                    '>=',
                    $activeSince
                )
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Inactive Groups
            |--------------------------------------------------------------------------
            */
            $inactiveGroups = max(
                $totalConnected - $activeGroups,
                0
            );

            /*
            |--------------------------------------------------------------------------
            | Disconnected Groups
            |--------------------------------------------------------------------------
            */
            $disconnectedGroups = TelegramGroup::query()
                ->where(
                    'status',
                    'disconnected'
                )
                ->count();

            return [
                'total_connected' =>
                    $totalConnected,

                'online' =>
                    $onlineGroups,

                'offline' =>
                    $offlineGroups,

                'active' =>
                    $activeGroups,

                'inactive' =>
                    $inactiveGroups,

                'disconnected' =>
                    $disconnectedGroups,

                'activity_timeout_seconds' =>
                    self::MAX_AGE_GROUP_ACTIVITY,
            ];
        } catch (Throwable $e) {
            Log::error(
                'Health: Telegram group summary failed',
                [
                    'error' =>
                        $e->getMessage(),
                ]
            );

            return [
                'total_connected' => 0,
                'online' => 0,
                'offline' => 0,
                'active' => 0,
                'inactive' => 0,
                'disconnected' => 0,
                'activity_timeout_seconds' =>
                    self::MAX_AGE_GROUP_ACTIVITY,
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Queue Worker
    |--------------------------------------------------------------------------
    */
    private function checkQueueWorker(): array
    {
        $beat = $this->heartbeatFresh(
            self::KEY_QUEUE,
            self::MAX_AGE_QUEUE
        );

        return $this->result(
            key: 'queue',

            label: 'Queue Worker',

            ok: $beat['ok'],

            status:
                $beat['ok']
                    ? 'active'
                    : 'stopped',

            extra: [
                'last_heartbeat' =>
                    $beat['last_at'],

                'heartbeat_age_seconds' =>
                    $beat['age_seconds'],
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Scheduler
    |--------------------------------------------------------------------------
    */
    private function checkScheduler(): array
    {
        $beat = $this->heartbeatFresh(
            self::KEY_SCHEDULER,
            self::MAX_AGE_SCHEDULER
        );

        return $this->result(
            key: 'scheduler',

            label: 'Scheduler',

            ok: $beat['ok'],

            status:
                $beat['ok']
                    ? 'running'
                    : 'stopped',

            extra: [
                'last_heartbeat' =>
                    $beat['last_at'],

                'heartbeat_age_seconds' =>
                    $beat['age_seconds'],
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Heartbeat helper
    |--------------------------------------------------------------------------
    */
    private function heartbeatFresh(
        string $key,
        int $maxAgeSeconds
    ): array {
        try {
            $lastBeat = (int) Cache::get(
                $key,
                0
            );
        } catch (Throwable $e) {
            Log::warning(
                'Health: Heartbeat cache unavailable',
                [
                    'key' =>
                        $key,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            return [
                'ok' => false,

                'last_at' => null,

                'age_seconds' => null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Heartbeat never received
        |--------------------------------------------------------------------------
        */
        if ($lastBeat <= 0) {
            return [
                'ok' => false,

                'last_at' => null,

                'age_seconds' => null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Calculate heartbeat age
        |--------------------------------------------------------------------------
        */
        $age = max(
            now()->timestamp - $lastBeat,
            0
        );

        return [
            'ok' =>
                $age <= $maxAgeSeconds,

            'last_at' =>
                now()
                    ->subSeconds($age)
                    ->toIso8601String(),

            'age_seconds' =>
                $age,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Standard service response
    |--------------------------------------------------------------------------
    */
    private function result(
        string $key,
        string $label,
        bool $ok,
        string $status,
        array $extra = []
    ): array {
        return array_merge(
            [
                'key' =>
                    $key,

                'label' =>
                    $label,

                'ok' =>
                    $ok,

                'status' =>
                    $status,
            ],
            $extra
        );
    }
}