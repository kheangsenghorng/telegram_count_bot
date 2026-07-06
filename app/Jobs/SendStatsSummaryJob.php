<?php

namespace App\Jobs;

use App\Models\TelegramGroup;
use App\Services\PaymentStatsService;
use App\Services\TelegramBotService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendStatsSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600; // 10 min max — plenty for large group counts

    public function __construct(
        public string $period = 'day'
    ) {}

    public function handle(
        PaymentStatsService $stats,
        TelegramBotService $bot
    ): void {
        Log::info('Stats summary job started', ['period' => $this->period]);

        $sent   = 0;
        $failed = 0;

        TelegramGroup::query()
            ->whereNotNull('group_id')
            ->where('status', 'connected')
            ->chunkById(100, function ($groups) use ($stats, $bot, &$sent, &$failed) {
                foreach ($groups as $group) {
                    $this->sendToGroup($group, $stats, $bot) ? $sent++ : $failed++;

                    usleep(50_000); // ~20 sends/sec, under Telegram's ceiling
                }
            });

        Log::info('Stats summary job finished', [
            'period' => $this->period,
            'sent'   => $sent,
            'failed' => $failed,
        ]);
    }

    private function sendToGroup(
        TelegramGroup $group,
        PaymentStatsService $stats,
        TelegramBotService $bot
    ): bool {
        try {
            $summary = match ($this->period) {
                'day'   => $stats->day($group->telegramGroupsID, 'scheduler'),
                'week'  => $stats->weekByNumber($group->telegramGroupsID, $this->currentWeekOfMonth(), 'scheduler'),
                'month' => $stats->month($group->telegramGroupsID, 'scheduler'),
                'year'  => $stats->year($group->telegramGroupsID, 'scheduler'),
                default => $stats->day($group->telegramGroupsID, 'scheduler'),
            };

            $result = $bot->sendMessage($group->group_id, $summary);
        } catch (\Throwable $e) {
            // Only stats-building errors land here now —
            // TelegramBotService returns errors as arrays, it doesn't throw.
            Log::warning('Stats summary failed for group', [
                'group'  => $group->telegramGroupsID,
                'period' => $this->period,
                'error'  => $e->getMessage(),
            ]);

            return false;
        }

        if (($result['ok'] ?? false) === true) {
            return true;
        }

        // Bot was kicked / group deleted → stop sending to it
        if (($result['error_code'] ?? 0) === 403) {
            $group->update(['status' => 'disconnected']);

            Log::info('Group auto-disconnected (bot removed)', [
                'group' => $group->telegramGroupsID,
            ]);
        }

        Log::warning('Stats summary failed for group', [
            'group'      => $group->telegramGroupsID,
            'period'     => $this->period,
            'error_code' => $result['error_code'] ?? null,
            'error'      => $result['description'] ?? 'unknown',
        ]);

        return false;
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Stats summary job failed completely', [
            'period' => $this->period,
            'error'  => $e->getMessage(),
        ]);
    }

    private function currentWeekOfMonth(): int
    {
        $now  = Carbon::now('Asia/Phnom_Penh');
        $week = (int) ceil($now->day / 7);

        return min($week, 4);
    }
}