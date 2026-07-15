<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Models\User;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class AccountHandler
{

    public function __construct(
        private readonly TelegramBotService $telegram,
    ) {}

    public static function connectedUserKey(string $telegramId): string
    {
        return "account:telegram:{$telegramId}";
    }

    public static function invalidateConnected(string $telegramId): void
    {
        Cache::forget(self::connectedUserKey($telegramId));
    }

    public function start(
        array $chat,
        array $from,
        string $text = ''
    ): JsonResponse {
        $chatId = (string) data_get($chat, 'id');
        $chatType = (string) data_get($chat, 'type', '');
        $telegramId = (string) data_get($from, 'id');

        if ($chatType !== 'private') {
            $this->telegram->sendMessage(
                $chatId,
                '🔒 Please open this bot in a private chat.'
            );

            return response()->json(['ok' => true]);
        }

        if ($telegramId === '') {
            Log::warning('Telegram /start missing sender ID', [
                'chat' => $chat,
                'from' => $from,
            ]);

            return response()->json(['ok' => true]);
        }

        try {
            $payload = $this->getStartPayload($text);

            /*
            |--------------------------------------------------------------------------
            | Link an existing website account
            |--------------------------------------------------------------------------
            */
            if ($payload !== null && str_starts_with($payload, 'user_')) {
                return $this->connectExistingAccount(
                    chatId: $chatId,
                    telegramId: $telegramId,
                    payload: $payload,
                    from: $from,
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Normal /start
            |--------------------------------------------------------------------------
            | Create a new user automatically or update Telegram profile data.
            */
            $user = $this->createOrUpdateTelegramUser(
                telegramId: $telegramId,
                from: $from,
            );

            self::invalidateConnected($telegramId);

            $message = $user->wasRecentlyCreated
                ? $this->newAccountMessage($user)
                : $this->welcomeBackMessage($user);

            $this->telegram->sendMessage(
                $chatId,
                $message,
                array_merge(
                    ['parse_mode' => 'HTML'],
                    $this->mainKeyboard()
                )
            );

            return response()->json([
                'ok' => true,
                'user_created' => $user->wasRecentlyCreated,
                'user_uuid' => $user->uuid,
            ]);
        } catch (Throwable $exception) {
            Log::error('Telegram /start failed', [
                'telegram_id' => $telegramId,
                'chat_id' => $chatId,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            $this->telegram->sendMessage(
                $chatId,
                "❌ We couldn't create your account right now.\n\nPlease try again."
            );

            return response()->json(['ok' => true]);
        }
    }

    private function createOrUpdateTelegramUser(
        string $telegramId,
        array $from
    ): User {
        $firstName = $this->nullableString(
            data_get($from, 'first_name')
        );

        $lastName = $this->nullableString(
            data_get($from, 'last_name')
        );

        $username = $this->nullableString(
            data_get($from, 'username')
        );

        $user = User::query()
            ->where('telegram_id', $telegramId)
            ->first();

        if ($user) {
            $user->update([
                'telegram_username' => $username,
                'telegram_first_name' => $firstName,
                'telegram_last_name' => $lastName,
                'first_name' => $firstName ?? $user->first_name,
                'last_name' => $lastName ?? $user->last_name,
                'status' => 'active',
                'last_login_at' => now(),
            ]);

            return $user->refresh();
        }

        return User::query()->create([
            /*
            |--------------------------------------------------------------------------
            | FIX #2 — uuid must be set explicitly
            |--------------------------------------------------------------------------
            | Remove this line ONLY if your User model auto-generates uuid
            | in booted()/creating(). Without a uuid, UserSubscription::activeFor()
            | lookups silently break for auto-created users.
            */
            'uuid' => (string) Str::uuid(),

            'telegram_id' => $telegramId,
            'telegram_username' => $username,
            'telegram_first_name' => $firstName,
            'telegram_last_name' => $lastName,
            'first_name' => $firstName ?? 'Telegram User',
            'last_name' => $lastName,

            /*
            |--------------------------------------------------------------------------
            | FIX #3 — placeholder email
            |--------------------------------------------------------------------------
            | Laravel's default users table has email NOT NULL + UNIQUE.
            | One placeholder per Telegram ID keeps the constraint happy.
            | Remove this line if your email column is nullable.
            */
            'email' => "tg_{$telegramId}@telegram.local",

            /*
            |--------------------------------------------------------------------------
            | FIX #1 — hashed password
            |--------------------------------------------------------------------------
            | Hash::make() is safe even if the model also has the 'hashed' cast
            | (Laravel detects already-hashed values and won't double-hash).
            */
            'password' => Hash::make(Str::random(64)),

            'role' => 'user',
            'status' => 'active',
            'last_login_at' => now(),
        ]);
    }

    private function connectExistingAccount(
        string $chatId,
        string $telegramId,
        string $payload,
        array $from
    ): JsonResponse {
        $uuid = substr($payload, strlen('user_'));

        if ($uuid === '') {
            $this->telegram->sendMessage(
                $chatId,
                '❌ Invalid account connection link.'
            );

            return response()->json(['ok' => true]);
        }

        $user = User::query()
            ->where('uuid', $uuid)
            ->first();

        if (! $user) {
            $this->telegram->sendMessage(
                $chatId,
                "❌ Account not found.\n\nPlease open the bot again from your payment page."
            );

            return response()->json(['ok' => true]);
        }

        /*
         * Stop one Telegram account from accidentally being linked
         * to multiple application accounts.
         */
        $alreadyLinked = User::query()
            ->where('telegram_id', $telegramId)
            ->where('uuid', '!=', $user->uuid)
            ->exists();

        if ($alreadyLinked) {
            $this->telegram->sendMessage(
                $chatId,
                "❌ This Telegram account is already connected to another account."
            );

            return response()->json(['ok' => true]);
        }

        $user->update([
            'telegram_id' => $telegramId,
            'telegram_username' => $this->nullableString(
                data_get($from, 'username')
            ),
            'telegram_first_name' => $this->nullableString(
                data_get($from, 'first_name')
            ),
            'telegram_last_name' => $this->nullableString(
                data_get($from, 'last_name')
            ),
            'last_login_at' => now(),
        ]);

        self::invalidateConnected($telegramId);

        $name = $this->escapeHtml(
            $user->telegram_first_name
                ?: $user->first_name
                ?: 'there'
        );

        $this->telegram->sendMessage(
            $chatId,
            "✅ <b>Telegram Connected Successfully!</b>\n\n"
            . "Hi {$name}, your account is now connected to this Telegram account.\n\n"
            . "You will receive payment notifications here.",
            array_merge(
                ['parse_mode' => 'HTML'],
                $this->mainKeyboard()
            )
        );

        return response()->json([
            'ok' => true,
            'connected' => true,
            'user_uuid' => $user->uuid,
        ]);
    }

    private function newAccountMessage(User $user): string
    {
        $name = $this->escapeHtml(
            $user->telegram_first_name
                ?: $user->first_name
                ?: 'there'
        );

        return "🎉 <b>Account Created Successfully!</b>\n\n"
            . "Welcome, {$name}!\n\n"
            . "Your Telegram account has been registered successfully.\n"
            . "You can now view packages, limits, and connect your groups.";
    }

    private function welcomeBackMessage(User $user): string
    {
        $name = $this->escapeHtml(
            $user->telegram_first_name
                ?: $user->first_name
                ?: 'there'
        );

        return "👋 <b>Welcome back, {$name}!</b>\n\n"
            . "✅ Your account is already registered.\n\n"
            . "Use the menu below to manage your packages and limits.";
    }

    private function mainKeyboard(): array
    {
        return [
            'reply_markup' => json_encode(
                [
                    'keyboard' => [
                        [
                            ['text' => '🆕 Package'],
                            ['text' => '📊 My Limits'],
                        ],
                        [
                            ['text' => '💬 Support'],
                            ['text' => '🌐 Domains'],
                        ],
                        [
                            ['text' => '🔒 Privacy Policy'],
                            ['text' => '📜 Terms of Service'],
                        ],
                    ],
                    'resize_keyboard' => true,
                    'is_persistent' => true,
                ],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
        ];
    }

    private function getStartPayload(string $text): ?string
    {
        $parts = preg_split('/\s+/', trim($text), 2);

        $payload = $parts[1] ?? null;

        return is_string($payload) && $payload !== ''
            ? $payload
            : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function escapeHtml(?string $value): string
    {
        return htmlspecialchars(
            $value ?? '',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
    public function showPrivacyPolicy(string $chatId): JsonResponse
    {
        $privacyUrl = route('privacy-policy');

        $this->telegram->sendMessage(
            $chatId,
            "🔒 <b>Privacy Policy</b>\n\n"
            . "សូមចុចប៊ូតុងខាងក្រោម ដើម្បីអានគោលការណ៍ឯកជនភាពរបស់យើង។",
            [
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '🔒 Open Privacy Policy',
                                'url' => $privacyUrl,
                            ],
                        ],
                    ],
                ],
            ]
        );

        return response()->json(['ok' => true]);
    }

   
}