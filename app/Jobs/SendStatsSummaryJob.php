<?php

namespace App\Jobs;

use App\Models\TelegramGroup;
use App\Services\PaymentStatsService;
use App\Services\TelegramBotService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendStatsSummaryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $period = 'day'
    ) {}

    public function handle(
        PaymentStatsService $stats,
        TelegramBotService $bot
    ): void {
        Log::info('Stats summary job started', [
            'period' => $this->period,
        ]);

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        $query = TelegramGroup::query()
            ->where('status', 'connected')
            ->whereNotNull('group_id')
            ->whereNotNull('telegramGroupsID')
            ->orderBy('created_at');

        $totalGroups = (clone $query)->count();

        Log::info('Stats summary groups found', [
            'period' => $this->period,
            'total_groups' => $totalGroups,
        ]);

        if ($totalGroups === 0) {
            Log::warning('Stats summary skipped: no connected Telegram groups found', [
                'period' => $this->period,
            ]);

            return;
        }

        $query->chunk(100, function (Collection $groups) use ($stats, $bot, &$sent, &$failed, &$skipped) {
            foreach ($groups as $group) {
                if (! $group->group_id || ! $group->telegramGroupsID) {
                    $skipped++;

                    Log::warning('Stats summary skipped group: missing group_id or telegramGroupsID', [
                        'group_id' => $group->group_id,
                        'telegramGroupsID' => $group->telegramGroupsID,
                    ]);

                    continue;
                }

                $ok = $this->sendToGroup($group, $stats, $bot);

                if ($ok) {
                    $sent++;
                } else {
                    $failed++;
                }

                usleep(50_000);
            }
        });

        Log::info('Stats summary job finished', [
            'period' => $this->period,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
        ]);
    }

    private function sendToGroup(
        TelegramGroup $group,
        PaymentStatsService $stats,
        TelegramBotService $bot
    ): bool {
        $summary = '';
    
        try {
            $summary = $this->buildSummary($group, $stats);
        } catch (\Throwable $e) {
            Log::error('Stats summary build failed for group', [
                'group_id' => $group->group_id,
                'telegramGroupsID' => $group->telegramGroupsID,
                'period' => $this->period,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
    
            return false;
        }
    
        $summary = trim((string) $summary);
    
        if ($summary === '') {
            Log::warning('Stats summary empty, skipped sending', [
                'group_id' => $group->group_id,
                'telegramGroupsID' => $group->telegramGroupsID,
                'period' => $this->period,
            ]);
    
            return false;
        }
    
        try {
            $result = $bot->sendMessage($group->group_id, $summary);
        } catch (\Throwable $e) {
            Log::error('Stats summary Telegram send exception', [
                'group_id' => $group->group_id,
                'telegramGroupsID' => $group->telegramGroupsID,
                'period' => $this->period,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
    
            return false;
        }
    
        if (($result['ok'] ?? false) === true) {
            Log::info('Stats summary sent to group', [
                'group_id' => $group->group_id,
                'telegramGroupsID' => $group->telegramGroupsID,
                'period' => $this->period,
            ]);
    
            return true;
        }
    
        if (($result['error_code'] ?? 0) === 403) {
            $group->update([
                'status' => 'disconnected',
            ]);
    
            Log::info('Telegram group auto-disconnected because bot was removed', [
                'group_id' => $group->group_id,
                'telegramGroupsID' => $group->telegramGroupsID,
            ]);
        }
    
        Log::warning('Stats summary Telegram send failed', [
            'group_id' => $group->group_id,
            'telegramGroupsID' => $group->telegramGroupsID,
            'period' => $this->period,
            'error_code' => $result['error_code'] ?? null,
            'error' => $result['description'] ?? 'unknown',
            'result' => $result,
        ]);
    
        return false;
    }

    private function buildSummary(
        TelegramGroup $group,
        PaymentStatsService $stats
    ): mixed {
        return match ($this->period) {
            'day' => $stats->day($group->telegramGroupsID, 'scheduler'),
    
            'week' => $stats->weekByNumber(
                $group->telegramGroupsID,
                $this->currentWeekOfMonth(),
                'scheduler'
            ),
    
            'month' => $stats->month($group->telegramGroupsID, 'scheduler'),
    
            'year' => $stats->year($group->telegramGroupsID, 'scheduler'),
    
            default => $stats->day($group->telegramGroupsID, 'scheduler'),
        };
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Stats summary job failed completely', [
            'period' => $this->period,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }

    private function currentWeekOfMonth(): int
    {
        $timezone = config('app.timezone', 'Asia/Phnom_Penh');

        $now = Carbon::now($timezone);

        $week = (int) ceil($now->day / 7);

        return min($week, 4);
    }
}