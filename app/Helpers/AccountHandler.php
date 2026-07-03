<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Models\User;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;

class AccountHandler
{
    public function __construct(
        protected TelegramBotService $telegram,
    ) {}

    public function start(array $chat, array $from, string $text = ''): JsonResponse
    {
        $chatId = (string) data_get($chat, 'id');
        $chatType = (string) data_get($chat, 'type', 'private');

        $telegramId = (string) data_get($from, 'id');
        $username = data_get($from, 'username');
        $firstName = data_get($from, 'first_name');
        $lastName = data_get($from, 'last_name');

        if ($chatType !== 'private') {
            $this->telegram->sendMessage(
                $chatId,
                '🔒 Please open this bot in a private chat.'
            );

            return response()->json(['ok' => true]);
        }

        $payload = $this->getStartPayload($text);

        /*
        |--------------------------------------------------------------------------
        | Deep link from website/payment page
        |--------------------------------------------------------------------------
        | Example:
        | /start user_550e8400-e29b-41d4-a716-446655440000
        */
        if ($payload && str_starts_with($payload, 'user_')) {
            $uuid = str_replace('user_', '', $payload);

            $user = User::where('uuid', $uuid)->first();

            if (! $user) {
                $this->telegram->sendMessage(
                    $chatId,
                    "❌ Account not found.\n\nPlease open the bot again from your payment page."
                );

                return response()->json(['ok' => true]);
            }

            $user->update([
                'telegram_id' => $chatId,
                'telegram_username' => $username,
                'telegram_first_name' => $firstName,
                'telegram_last_name' => $lastName,
            ]);

            $this->telegram->sendMessage(
                $chatId,
                "✅ *Telegram Connected Successfully!*\n\n" .
                "Hi {$firstName}, your account is now connected with this Telegram chat.\n\n" .
                "You will receive payment success notifications here."
            );

            return response()->json(['ok' => true]);
        }

        /*
        |--------------------------------------------------------------------------
        | Normal /start
        |--------------------------------------------------------------------------
        */
        $this->telegram->sendMessage(
            $chatId,
            "👋 Welcome!\n\n" .
            "Please open this bot from your payment page to connect your account.\n\n" .
            "After connecting, you will receive payment success notifications here."
        );

        return response()->json(['ok' => true]);
    }

    private function getStartPayload(string $text): ?string
    {
        $parts = explode(' ', trim($text), 2);

        return $parts[1] ?? null;
    }
}