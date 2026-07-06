<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\BotCallback;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    private const TIMEOUT_SECONDS = 15;

    private const UPLOAD_TIMEOUT_SECONDS = 60;

    private function token(): ?string
    {
        return config('services.telegram.bot_token');
    }

    private function apiUrl(string $method): string
    {
        return "https://api.telegram.org/bot{$this->token()}/{$method}";
    }

    // -------------------------------------------------------------------------
    // Core HTTP — EVERY Telegram API call goes through here
    // -------------------------------------------------------------------------

    private function request(string $method, array $payload, bool $retried = false): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(5)
                ->post($this->apiUrl($method), $payload);
        } catch (ConnectionException $e) {
            Log::error('Telegram connection failed', [
                'method' => $method,
                'error'  => $e->getMessage(),
            ]);

            return [
                'ok'          => false,
                'description' => 'Connection failed: ' . $e->getMessage(),
            ];
        }

        $json = $response->json();

        if (! is_array($json)) {
            Log::error('Telegram returned non-JSON response', [
                'method' => $method,
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);

            return [
                'ok'          => false,
                'description' => 'Invalid response from Telegram',
            ];
        }

        // 429 Too Many Requests → wait as Telegram instructs, retry once
        if (($json['error_code'] ?? 0) === 429 && ! $retried) {
            $wait = min((int) ($json['parameters']['retry_after'] ?? 1), 5);

            sleep($wait);

            return $this->request($method, $payload, retried: true);
        }

        if (($json['ok'] ?? false) !== true) {
            Log::warning('Telegram API error', [
                'method'      => $method,
                'chat_id'     => $payload['chat_id'] ?? null,
                'error_code'  => $json['error_code'] ?? null,
                'description' => $json['description'] ?? null,
            ]);
        }

        return $json;
    }

    // -------------------------------------------------------------------------
    // Sending
    // -------------------------------------------------------------------------

    public function sendMessage(string|int $chatId, string $text, array $options = []): array
    {
        $payload = array_merge([
            'chat_id' => $chatId,
            'text'    => $text,
        ], $options);

        $result = $this->request('sendMessage', $payload);

        // Fallback: if Markdown failed to parse (e.g. "_" in a username),
        // resend as plain text instead of silently dropping the message.
        if (
            ($result['ok'] ?? false) === false
            && isset($payload['parse_mode'])
            && str_contains((string) ($result['description'] ?? ''), "can't parse entities")
        ) {
            Log::notice('Telegram parse_mode fallback → plain text', [
                'chat_id' => $chatId,
            ]);

            unset($payload['parse_mode']);

            $result = $this->request('sendMessage', $payload);
        }

        return $result;
    }

    public function sendMarkdown(string|int $chatId, string $text, array $options = []): array
    {
        return $this->sendMessage($chatId, $text, array_merge([
            'parse_mode' => 'Markdown',
        ], $options));
    }

    public function sendHtml(string|int $chatId, string $text, array $options = []): array
    {
        return $this->sendMessage($chatId, $text, array_merge([
            'parse_mode' => 'HTML',
        ], $options));
    }

    /**
     * Show "typing…" / "upload_photo…" indicator while doing slow work
     * (e.g. creating a Bakong checkout or generating a QR image).
     */
    public function sendChatAction(string|int $chatId, string $action = 'typing'): array
    {
        return $this->request('sendChatAction', [
            'chat_id' => $chatId,
            'action'  => $action,
        ]);
    }

    public function deleteMessage(string|int $chatId, int $messageId): array
    {
        return $this->request('deleteMessage', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public function sendMainMenu(string|int $chatId, string $text): array
    {
        return $this->sendMessage($chatId, $text, [
            'reply_markup' => [
                'keyboard' => [
                    [
                        ['text' => '🆕 Package'],
                        ['text' => '📊 My Limits'],
                    ],
                    [
                        ['text' => '🌐 Domains'],
                        ['text' => '💬 Support'],
                    ],
                    [
                        ['text' => '🔒 Privacy Policy'],
                        ['text' => '📜 Terms of Service'],
                    ],
                ],
                'resize_keyboard'   => true,
                'one_time_keyboard' => false,
                'is_persistent'     => true,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Stats — main menu
    // -------------------------------------------------------------------------

    public function sendStatsMenu(string|int $chatId): array
    {
        return $this->request('sendMessage', [
            'chat_id'      => $chatId,
            'text'         => "📊 *ស្ថិតិការទូទាត់*\nជ្រើសរយៈពេលដើម្បីមើល៖",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($this->mainStatsKeyboard()),
        ]);
    }

    public function editToStatsMenu(string|int $chatId, int $messageId, string $text): array
    {
        return $this->request('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($this->mainStatsKeyboard()),
        ]);
    }

    public function editToWeekMenu(string|int $chatId, int $messageId): array
    {
        $now        = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();

        $weekButtons = [];

        for ($week = 1; $week <= 4; $week++) {
            $weekStart = $monthStart->copy()->addDays(($week - 1) * 7);

            if ($weekStart->isAfter($now)) {
                break;
            }

            $weekEnd = ($week === 4)
                ? $now->copy()->endOfMonth()
                : $weekStart->copy()->addDays(6)->endOfDay();

            $displayEnd = $weekEnd->isAfter($now)
                ? $now->copy()
                : $weekEnd->copy();

            $range = $weekStart->format('d M') . '–' . $displayEnd->format('d M');

            $weekButtons[] = [
                'text'          => "សប្ដាហ៍ទី {$week} ({$range})",
                'callback_data' => BotCallback::STATS_WEEK_PREFIX . $week,
            ];
        }

        $rows   = array_chunk($weekButtons, 2);
        $rows[] = $this->backButton();

        return $this->request('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => "📆 *ជ្រើសសប្ដាហ៍ — " . $now->format('F Y') . "*",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => $rows,
            ]),
        ]);
    }

    public function editToMonthMenu(string|int $chatId, int $messageId, array $monthsWithData): array
    {
        $now = Carbon::now();

        $monthButtons = [];

        for ($month = 1; $month <= 12; $month++) {
            if ($month > $now->month) {
                continue;
            }

            if ($month !== $now->month && ! in_array($month, $monthsWithData, true)) {
                continue;
            }

            $label = Carbon::create($now->year, $month)->translatedFormat('F');

            $monthButtons[] = [
                'text'          => "🗓 {$label}",
                'callback_data' => BotCallback::STATS_MONTH_PREFIX . $month,
            ];
        }

        $rows   = array_chunk($monthButtons, 3);
        $rows[] = $this->backButton();

        return $this->request('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => "🗓 *ជ្រើសខែ — " . $now->format('Y') . "*",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => $rows,
            ]),
        ]);
    }

    public function editToYearMenu(string|int $chatId, int $messageId, array $yearsWithData): array
    {
        $yearButtons = [];

        foreach ($yearsWithData as $year) {
            $yearButtons[] = [
                'text'          => "📊 {$year}",
                'callback_data' => BotCallback::STATS_YEAR_PREFIX . $year,
            ];
        }

        $rows   = array_chunk($yearButtons, 3);
        $rows[] = $this->backButton();

        return $this->request('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => "📊 *ជ្រើសឆ្នាំ*",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => $rows,
            ]),
        ]);
    }

    public function editMessage(
        string|int $chatId,
        int $messageId,
        string $text,
        array $inlineKeyboard = []
    ): array {
        $payload = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ];

        $payload['reply_markup'] = ! empty($inlineKeyboard)
            ? json_encode(['inline_keyboard' => $inlineKeyboard])
            : json_encode($this->mainStatsKeyboard());

        return $this->request('editMessageText', $payload);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): array
    {
        $payload = [
            'callback_query_id' => $callbackQueryId,
        ];

        if ($text !== '') {
            $payload['text'] = $text;
        }

        return $this->request('answerCallbackQuery', $payload);
    }

    // -------------------------------------------------------------------------
    // Keyboards
    // -------------------------------------------------------------------------

    private function mainStatsKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    [
                        'text'          => '📅 ថ្ងៃនេះ',
                        'callback_data' => BotCallback::STATS_DAY,
                    ],
                    [
                        'text'          => '📆 សប្ដាហ៍នេះ',
                        'callback_data' => BotCallback::STATS_WEEK,
                    ],
                ],
                [
                    [
                        'text'          => '🗓 ខែនេះ',
                        'callback_data' => BotCallback::STATS_MONTH,
                    ],
                    [
                        'text'          => '📊 ឆ្នាំនេះ',
                        'callback_data' => BotCallback::STATS_YEAR,
                    ],
                ],
            ],
        ];
    }

    private function backButton(): array
    {
        return [
            [
                'text'          => '◀️ ត្រឡប់ក្រោយ',
                'callback_data' => BotCallback::STATS_BACK,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Webhook management
    // -------------------------------------------------------------------------

    public function setWebhook(string $url = '', string $secretToken = ''): array
    {
        $url = $url ?: rtrim(config('app.url'), '/') . '/api/telegram/webhook';

        $payload = [
            'url'                  => $url,
            'drop_pending_updates' => true,
        ];

        if ($secretToken !== '') {
            $payload['secret_token'] = $secretToken;
        }

        return $this->request('setWebhook', $payload);
    }

    public function webhookInfo(): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->get($this->apiUrl('getWebhookInfo'));
        } catch (ConnectionException $e) {
            return ['ok' => false, 'description' => $e->getMessage()];
        }

        return $response->json() ?? ['ok' => false];
    }

    public function deleteWebhook(): array
    {
        return $this->request('deleteWebhook', [
            'drop_pending_updates' => true,
        ]);
    }

    public function isAdmin(string|int $chatId, string|int $userId): bool
    {
        $response = $this->request('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);

        $status = $response['result']['status'] ?? '';

        return in_array($status, ['administrator', 'creator'], true);
    }

    public function addToGroupUrl(string $payload = 'true'): string
    {
        $username = config('services.telegram.bot_username');

        $username = ltrim((string) $username, '@');

        return 'https://t.me/' . $username . '?startgroup=' . urlencode($payload);
    }
    public function editPlainMessage(
        string|int $chatId,
        int $messageId,
        string $text,
        array $inlineKeyboard = []
    ): array {
        return $this->request('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ]);
    }
    
    public function editMessageCaption(
        string|int $chatId,
        int $messageId,
        string $caption,
        array $inlineKeyboard = []
    ): array {
        return $this->request('editMessageCaption', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'caption'      => $caption,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ]);
    }

}