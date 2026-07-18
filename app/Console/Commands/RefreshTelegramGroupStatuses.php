<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramGroup;
use Illuminate\Console\Command;

final class RefreshTelegramGroupStatuses extends Command
{
    protected $signature = 'telegram:refresh-group-statuses';

    protected $description = 'Refresh Telegram group activity statuses';

    private const INACTIVE_AFTER_SECONDS = 120;

    public function handle(): int
    {
        $inactiveBefore = now()->subSeconds(
            self::INACTIVE_AFTER_SECONDS
        );

        $updated = TelegramGroup::query()
            ->where('status', 'connected')
            ->where('activity_status', 'active')
            ->where(function ($query) use ($inactiveBefore) {
                $query
                    ->whereNull('last_activity_at')
                    ->orWhere(
                        'last_activity_at',
                        '<',
                        $inactiveBefore
                    );
            })
            ->update([
                'activity_status' => 'inactive',
            ]);

        $this->info(
            "Updated {$updated} inactive groups."
        );

        return self::SUCCESS;
    }
}