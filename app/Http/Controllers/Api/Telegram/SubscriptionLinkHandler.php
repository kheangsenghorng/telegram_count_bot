<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Helpers\KhmerDateFormatter;
use App\Models\TelegramGroup;
use App\Models\UserSubscription;
use App\Services\TelegramBotService;
use Illuminate\Support\Facades\Cache;

class SubscriptionLinkHandler
{
    public function __construct(
        protected TelegramBotService $telegram,
    ) {}

    private function escapeMarkdown(?string $text): string
    {
        $text = (string) $text;

        return preg_replace('/([_*`\[])/', '\\\\$1', $text) ?? $text;
    }

    private function formatPrice(float|int|string|null $price): string
    {
        return number_format((float) $price, 2);
    }

    public function sendPaymentSuccess(UserSubscription $subscription, string|int $chatId): void
    {
        /**
         * Prevent duplicate success message because frontend may call /check many times.
         */
        $lockKey = 'subscription_success_sent_' . $subscription->userSubscriptionsID;

        if (! Cache::add($lockKey, true, now()->addDays(7))) {
            return;
        }

        $package = $subscription->package;

        $name  = $this->escapeMarkdown($package?->name ?? '');
        $price = $this->formatPrice($package?->price);

        $remaining = $subscription->remainingPayments();

        $remainingLabel = $remaining === null
            ? '∞'
            : KhmerDateFormatter::formatNumber($remaining);

        $lines = [
            "✅ *ការទូទាត់ជោគជ័យ!*",
            "─────────────────────",
            "📦 កញ្ចប់: *{$name}*",
            "💰 តម្លៃ: *{$price} USD*",
            "💳 ការទូទាត់សរុប: *{$remainingLabel}*",
        ];

        $lines[] = $subscription->isLifetime()
            ? '📅 សុពលភាព: អចិន្ត្រៃយ៍'
            : '📅 ផុតកំណត់: ' . KhmerDateFormatter::formatDate($subscription->ends_at);

        $lines[] = "─────────────────────";
        $lines[] = "👇 ចុចប៊ូតុងខាងក្រោម ដើម្បីបន្ថែម Bot ទៅក្នុងក្រុមរបស់អ្នក។";
        $lines[] = "Bot នឹងភ្ជាប់ក្រុមជាមួយកញ្ចប់ដោយស្វ័យប្រវត្តិ។";

        $addUrl = $this->telegram->addToGroupUrl(
            'sub-' . $subscription->userSubscriptionsID
        );

        $this->telegram->sendMessage(
            $chatId,
            implode("\n", $lines),
            [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '➕ បន្ថែម Bot ទៅក្នុងក្រុម',
                                'url'  => $addUrl,
                            ],
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    public function handleGroupStart(array $message): bool
    {
        $chat = $message['chat'] ?? [];
        $text = (string) ($message['text'] ?? '');

        if (! in_array($chat['type'] ?? '', ['group', 'supergroup'], true)) {
            return false;
        }

        /**
         * Matches:
         * /start sub-123
         * /start@ServiceFixitBot sub-123
         */
        if (! preg_match('/^\/start(?:@\w+)?\s+sub-([\w-]+)/', $text, $m)) {
            return false;
        }

        $subscription = UserSubscription::with('package')->find($m[1]);

        if (! $subscription || ! $subscription->isActive()) {
            $this->telegram->sendMessage(
                $chat['id'],
                '❌ រកមិនឃើញកញ្ចប់ ឬកញ្ចប់ផុតកំណត់ហើយ។'
            );

            return true;
        }

        $package = $subscription->package;

        $isUnlimited = method_exists($package, 'isUnlimitedGroups')
            && $package->isUnlimitedGroups();

        if (! $isUnlimited) {
            $connected = TelegramGroup::query()
                ->where('subscription_id', $subscription->userSubscriptionsID)
                ->where('status', 'connected')
                ->where('group_id', '!=', (string) $chat['id'])
                ->count();

            if ($connected >= (int) $package->group_limit) {
                $limitKh = KhmerDateFormatter::formatNumber((int) $package->group_limit);

                $this->telegram->sendMessage(
                    $chat['id'],
                    "❌ កញ្ចប់របស់អ្នកអនុញ្ញាតត្រឹម *{$limitKh}* ក្រុមប៉ុណ្ណោះ។",
                    ['parse_mode' => 'Markdown']
                );

                return true;
            }
        }

     // ── Connect (or reconnect) the group ─────────────────────────────
        TelegramGroup::updateOrCreate(
            ['group_id' => (string) $chat['id']],
            [
                'user_id'           => $subscription->user_id,
                'subscription_id'   => $subscription->userSubscriptionsID,
                'group_name'        => $chat['title'] ?? '',
                'group_type'        => $chat['type'] ?? 'group',
                'telegram_username' => $chat['username'] ?? null,
                'status'            => 'connected',
                'bot_added_at'      => now(),
                'connected_at'      => now(),
            ]
        );

        $this->telegram->sendMessage(
            $chat['id'],
            implode("\n", [
                '✅ *ក្រុមត្រូវបានភ្ជាប់ជោគជ័យ!*',
                'Bot នឹងតាមដានការទូទាត់ក្នុងក្រុមនេះ ហើយផ្ញើរបាយការណ៍ស្ថិតិជូនអ្នក។',
            ]),
            ['parse_mode' => 'Markdown']
        );

        return true;
    }
}