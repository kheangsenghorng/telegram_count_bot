<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;

class SupportHandler
{
    public function __construct(
        protected TelegramBotService $telegram,
    ) {}

    public function showContact(string $chatId): JsonResponse
    {
        $username = config('services.telegram.support_username');
        $phone    = config('services.telegram.support_phone');
        $hours    = config('services.telegram.support_hours');

        $text = implode("\n", [
            "💬 *ទំនាក់ទំនងផ្នែកជំនួយ*",
            "─────────────────────",
            "👤 Telegram: @{$username}",
            "📞 លេខទូរស័ព្ទ: `{$phone}`",
            "🕐 ម៉ោងធ្វើការ: {$hours}",
            "─────────────────────",
            "សូមផ្ញើសាររបស់អ្នកទៅកាន់ admin ខាងលើ។",
        ]);

        $keyboard = [
            [
                [
                    'text' => '💬 ទំនាក់ទំនង Admin',
                    'url'  => "https://t.me/{$username}",
                ],
            ],
        ];

        $this->telegram->sendMessage($chatId, $text, $keyboard);

        return response()->json(['ok' => true]);
    }
}