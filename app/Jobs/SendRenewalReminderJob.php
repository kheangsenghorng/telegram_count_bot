<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Helpers\KhmerDateFormatter;
use App\Models\UserSubscription;
use App\Services\TelegramBotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendRenewalReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public string $subscriptionId,
    ) {}

    public function handle(TelegramBotService $telegram): void
    {
        $sub = UserSubscription::query()
            ->with(['user', 'package'])
            ->where('userSubscriptionsID', $this->subscriptionId)
            ->first();

        if (! $sub) {
            return;
        }

        // Re-check the guards — state may have changed between
        // dispatch and execution (renewed, cancelled, already reminded).
        if (
            $sub->status !== 'active'
            || $sub->ends_at === null
            || $sub->renewal_reminded_at !== null
            || $sub->ends_at->isPast()
        ) {
            return;
        }

        $chatId = $sub->user?->telegram_id; // ← ADJUST column name if different

        if (! $chatId) {
            Log::warning('Renewal reminder skipped: user has no telegram_id', [
                'subscription_id' => $this->subscriptionId,
            ]);

            // Mark anyway so we don't retry a user we can't reach.
            $sub->forceFill(['renewal_reminded_at' => now()])->save();

            return;
        }

        $package = $sub->package;

        $pkgName  = e($package?->name ?? 'កញ្ចប់សេវា');
        $endsAtKh = KhmerDateFormatter::formatDate($sub->ends_at);
        $daysLeft = (int) now()->startOfDay()->diffInDays($sub->ends_at->startOfDay());
        $daysKh   = KhmerDateFormatter::formatNumber(max(0, $daysLeft));

        $remaining = $sub->remainingPayments();
        $remainingLabel = $remaining === null
            ? '∞'
            : KhmerDateFormatter::formatNumber($remaining);

        $lines = [
            '⏰ <b>ការរំលឹកបន្តកញ្ចប់</b>',
            '─────────────────────',
            "📦 កញ្ចប់: <b>{$pkgName}</b>",
            "📅 ផុតកំណត់: <b>{$endsAtKh}</b> (នៅសល់ {$daysKh} ថ្ងៃ)",
            "💳 ការទូទាត់នៅសល់: {$remainingLabel}",
            '─────────────────────',
            '🔄 បន្តកញ្ចប់ឥឡូវនេះ ដើម្បីកុំឱ្យសេវាកម្មរបស់អ្នកផ្អាក។',
        ];

        if ($remaining !== null && $remaining > 0) {
            $lines[] = "➕ ការទូទាត់នៅសល់ <b>{$remainingLabel}</b> នឹងបូកបញ្ចូលទៅកញ្ចប់ថ្មី។";
        }

        $keyboard = [
            [
                [
                    'text'          => "🔄 បន្តកញ្ចប់ {$package?->name}",
                    // Reuses your existing buy flow in PackageHandler
                    'callback_data' => 'pkg_buy_' . $package?->packagesID,
                ],
            ],
            [
                [
                    'text'          => '📦 មើលកញ្ចប់ផ្សេងទៀត',
                    'callback_data' => 'pkg_show',
                ],
            ],
        ];

        $supportRaw = (string) config('services.telegram.support_username');

        if ($supportRaw !== '') {
            $keyboard[] = [
                [
                    'text' => '💬 ទំនាក់ទំនង Admin',
                    'url'  => "https://t.me/{$supportRaw}",
                ],
            ];
        }

        $result = $telegram->sendMessage(
            (string) $chatId,
            implode("\n", $lines),
            [
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode(
                    ['inline_keyboard' => $keyboard],
                    JSON_UNESCAPED_UNICODE
                ),
            ]
        );

        // Only mark as reminded when Telegram accepted the message,
        // so a failed send retries on the next scheduler run.
        if (($result['ok'] ?? false) === true) {
            $sub->forceFill(['renewal_reminded_at' => now()])->save();
        } else {
            Log::warning('Renewal reminder send failed', [
                'subscription_id' => $this->subscriptionId,
                'chat_id'         => $chatId,
                'result'          => $result,
            ]);
        }
    }
}