<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Models\User;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class AccountHandler
{
    public function __construct(
        protected TelegramBotService $telegram,
    ) {}

    public function start(array $chat, array $from): JsonResponse
    {
        $chatId   = (string) ($chat['id'] ?? '');
        $chatType = $chat['type'] ?? 'private';

        if ($chatType !== 'private') {
            $this->telegram->sendMessage($chatId, '👋 Please open the bot in a private chat and send /start.');
            return response()->json(['ok' => true]);
        }

        $telegramId = (string) ($from['id'] ?? '');
        $firstName  = $from['first_name'] ?? 'Telegram';
        $lastName   = $from['last_name']  ?? null;
        $username   = $from['username']   ?? null;

        $user = User::firstOrCreate(
            ['telegram_id' => $telegramId],
            [
                'uuid'                => (string) Str::uuid(),
                'first_name'          => $firstName,
                'last_name'           => $lastName,
                'email'               => "telegram_{$telegramId}@telegram.local",
                'telegram_username'   => $username,
                'telegram_first_name' => $firstName,
                'telegram_last_name'  => $lastName,
                'password'            => bcrypt(Str::random(32)),
                'role'                => 'user',
                'status'              => 'active',
            ]
        );

        $user->update([
            'first_name'          => $firstName,
            'last_name'           => $lastName,
            'telegram_username'   => $username,
            'telegram_first_name' => $firstName,
            'telegram_last_name'  => $lastName,
        ]);

        $this->telegram->sendMainMenu(
            $chatId,
            "✅ Account ready!\n\nHello {$firstName}\nTelegram ID: {$telegramId}"
        );

        return response()->json([
            'ok'          => true,
            'uuid'        => $user->uuid,
            'telegram_id' => $telegramId,
        ]);
    }
}