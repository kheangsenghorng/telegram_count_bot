<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\SystemStatusUpdated;
use App\Services\SystemStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BroadcastSystemStatus extends Command
{
    protected $signature = 'system:status-broadcast';

    protected $description =
        'Watch system health and broadcast realtime status changes';

    private const POLL_SECONDS = 1;

    public function handle(
        SystemStatusService $statusService
    ): int {
        $this->info('📡 System status broadcaster started.');

        set_time_limit(0);

        $previousState = null;

        while (true) {
            try {
                /*
                |--------------------------------------------------------------------------
                | Build latest complete status
                |--------------------------------------------------------------------------
                */
                $payload = $statusService->buildStatusPayload();

                /*
                |--------------------------------------------------------------------------
                | Extract only values that should trigger realtime updates
                |--------------------------------------------------------------------------
                |
                | We exclude:
                |
                | - checked_at
                | - last_heartbeat
                | - heartbeat_age_seconds
                |
                | because those values change constantly.
                |
                */
                $currentState = $this->extractComparableState(
                    $payload['services']
                );

                /*
                |--------------------------------------------------------------------------
                | Detect detailed changes
                |--------------------------------------------------------------------------
                */
                $changes = $this->detectChanges(
                    previousState: $previousState,
                    currentState: $currentState,
                    services: $payload['services'],
                );

                /*
                |--------------------------------------------------------------------------
                | Broadcast whenever comparable state changes
                |--------------------------------------------------------------------------
                |
                | Examples:
                |
                | API online → offline
                | Redis connected → disconnected
                | Active groups 4 → 5
                | Connected groups 10 → 11
                | Listener online → offline
                |
                */
                $hasChanged = $previousState === null
                    || $currentState !== $previousState;

                if ($hasChanged) {
                    $payload['event_type'] = $previousState === null
                        ? 'snapshot'
                        : 'status_changed';

                    $payload['changes'] = $changes;

                    SystemStatusUpdated::dispatch($payload);

                    $this->logChanges(
                        changes: $changes,
                        overall: $payload['overall'],
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Save current state for next comparison
                |--------------------------------------------------------------------------
                */
                $previousState = $currentState;
            } catch (Throwable $e) {
                Log::error(
                    'System status broadcaster error',
                    [
                        'error' => $e->getMessage(),
                        'exception' => $e::class,
                    ]
                );

                $this->error(
                    sprintf(
                        '[%s] Broadcast error: %s',
                        now()->format('H:i:s'),
                        $e->getMessage()
                    )
                );
            }

            sleep(self::POLL_SECONDS);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Extract comparable state
    |--------------------------------------------------------------------------
    */
    private function extractComparableState(array $services): array
{
    $state = [];

    foreach ($services as $key => $service) {
        $state[$key] = [
            'ok' => (bool) ($service['ok'] ?? false),
            'status' => (string) ($service['status'] ?? 'unknown'),
        ];

        /*
        |--------------------------------------------------------------------------
        | Include Telegram group counts
        |--------------------------------------------------------------------------
        */
        if ($key === 'bot') {
            $groups = $service['groups'] ?? [];

            $state[$key]['groups'] = [
                'total_connected' => (int) (
                    $groups['total_connected'] ?? 0
                ),

                'online' => (int) (
                    $groups['online'] ?? 0
                ),

                'offline' => (int) (
                    $groups['offline'] ?? 0
                ),

                'active' => (int) (
                    $groups['active'] ?? 0
                ),

                'inactive' => (int) (
                    $groups['inactive'] ?? 0
                ),

                'disconnected' => (int) (
                    $groups['disconnected'] ?? 0
                ),
            ];
        }
    }

    return $state;
}

    /*
    |--------------------------------------------------------------------------
    | Detect individual changes
    |--------------------------------------------------------------------------
    */
    private function detectChanges(
        ?array $previousState,
        array $currentState,
        array $services
    ): array {
        /*
        |--------------------------------------------------------------------------
        | Initial startup
        |--------------------------------------------------------------------------
        */
        if ($previousState === null) {
            return [];
        }

        $changes = [];

        foreach ($currentState as $key => $current) {
            $previous = $previousState[$key]
                ?? null;

            if ($previous === null) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Detect service health changes
            |--------------------------------------------------------------------------
            */
            $okChanged =
                ($previous['ok'] ?? false)
                !==
                ($current['ok'] ?? false);

            $statusChanged =
                ($previous['status'] ?? 'unknown')
                !==
                ($current['status'] ?? 'unknown');

            if ($okChanged || $statusChanged) {
                $changes[] = $this->makeServiceChange(
                    key: $key,
                    previous: $previous,
                    current: $current,
                    service: $services[$key] ?? [],
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Detect Telegram group count changes
            |--------------------------------------------------------------------------
            */
            if ($key === 'bot') {
                $groupChanges = $this->detectGroupChanges(
                    previousGroups: $previous['groups'] ?? [],
                    currentGroups: $current['groups'] ?? [],
                );

                $changes = array_merge(
                    $changes,
                    $groupChanges
                );
            }
        }

        return $changes;
    }

    /*
    |--------------------------------------------------------------------------
    | Build service health change
    |--------------------------------------------------------------------------
    */
    private function makeServiceChange(
        string $key,
        array $previous,
        array $current,
        array $service
    ): array {
        $previousOk = $previous['ok'] ?? false;
        $currentOk = $current['ok'] ?? false;

        if (
            $previousOk === true
            && $currentOk === false
        ) {
            $type = 'failed';
        } elseif (
            $previousOk === false
            && $currentOk === true
        ) {
            $type = 'recovered';
        } else {
            $type = 'changed';
        }

        return [
            'category' => 'service',

            'service' => $key,

            'label' => $service['label']
                ?? $key,

            'type' => $type,

            'previous_status' =>
                $previous['status']
                ?? 'unknown',

            'current_status' =>
                $current['status']
                ?? 'unknown',

            'ok' =>
                $currentOk,

            'changed_at' =>
                now()->toIso8601String(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Detect Telegram group count changes
    |--------------------------------------------------------------------------
    */
    private function detectGroupChanges(
        array $previousGroups,
        array $currentGroups
    ): array {
        $changes = [];

        $labels = [
            'total_connected' =>
                'Connected Groups',

            'online' =>
                'Online Groups',

            'offline' =>
                'Offline Groups',

            'active' =>
                'Active Groups',

            'inactive' =>
                'Inactive Groups',

            'disconnected' =>
                'Disconnected Groups',
        ];

        foreach ($labels as $key => $label) {
            $previousValue = (int) (
                $previousGroups[$key]
                ?? 0
            );

            $currentValue = (int) (
                $currentGroups[$key]
                ?? 0
            );

            if ($previousValue === $currentValue) {
                continue;
            }

            $changes[] = [
                'category' =>
                    'telegram_group',

                'service' =>
                    'bot',

                'metric' =>
                    $key,

                'label' =>
                    $label,

                'type' =>
                    'changed',

                'previous_value' =>
                    $previousValue,

                'current_value' =>
                    $currentValue,

                'difference' =>
                    $currentValue - $previousValue,

                'changed_at' =>
                    now()->toIso8601String(),
            ];
        }

        return $changes;
    }

    /*
    |--------------------------------------------------------------------------
    | Console logging
    |--------------------------------------------------------------------------
    */
    private function logChanges(
        array $changes,
        string $overall
    ): void {
        if ($changes === []) {
            $this->line(
                sprintf(
                    '[%s] Initial status broadcast → %s',
                    now()->format('H:i:s'),
                    $overall
                )
            );

            return;
        }

        foreach ($changes as $change) {
            /*
            |--------------------------------------------------------------------------
            | Telegram group count change
            |--------------------------------------------------------------------------
            */
            if (
                ($change['category'] ?? null)
                === 'telegram_group'
            ) {
                $difference = (int) (
                    $change['difference']
                    ?? 0
                );

                $icon = $difference > 0
                    ? '⬆️'
                    : '⬇️';

                $this->line(
                    sprintf(
                        '[%s] %s %s: %d → %d',
                        now()->format('H:i:s'),
                        $icon,
                        $change['label'],
                        $change['previous_value'],
                        $change['current_value']
                    )
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Service status change
            |--------------------------------------------------------------------------
            */
            $icon = match (
                $change['type']
                ?? 'changed'
            ) {
                'failed' => '🔴',
                'recovered' => '🟢',
                default => '🟡',
            };

            $this->line(
                sprintf(
                    '[%s] %s %s → %s',
                    now()->format('H:i:s'),
                    $icon,
                    $change['label'],
                    $change['current_status']
                    ?? 'unknown'
                )
            );
        }
    }
}