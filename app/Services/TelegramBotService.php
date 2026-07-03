<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\BotCallback;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    private function token(): ?string
    {
        return config('services.telegram.bot_token');
    }

    private function apiUrl(string $method): string
    {
        return "https://api.telegram.org/bot{$this->token()}/{$method}";
    }

    // -------------------------------------------------------------------------
    // Sending
    // -------------------------------------------------------------------------

    public function sendMessage(string|int $chatId, string $text, array $options = []): bool|array
    {
        $payload = array_merge([
            'chat_id' => $chatId,
            'text'    => $text,
        ], $options);

        $response = Http::post($this->apiUrl('sendMessage'), $payload);

        if (! $response->successful()) {
            Log::error('Telegram sendMessage failed', [
                'status'  => $response->status(),
                'body'    => $response->body(),
                'payload' => $payload,
            ]);
        }

        return $response->json() ?? false;
    }

    public function sendMarkdown(string|int $chatId, string $text, array $options = []): bool|array
    {
        return $this->sendMessage($chatId, $text, array_merge([
            'parse_mode' => 'Markdown',
        ], $options));
    }

    public function sendHtml(string|int $chatId, string $text, array $options = []): bool|array
    {
        return $this->sendMessage($chatId, $text, array_merge([
            'parse_mode' => 'HTML',
        ], $options));
    }

    public function deleteMessage(string|int $chatId, int $messageId): bool|array
    {
        $response = Http::post($this->apiUrl('deleteMessage'), [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
        ]);

        if (! $response->successful()) {
            Log::warning('Telegram deleteMessage failed', [
                'status'     => $response->status(),
                'body'       => $response->body(),
                'chat_id'    => $chatId,
                'message_id' => $messageId,
            ]);
        }

        return $response->json() ?? false;
    }

    public function sendMainMenu(string|int $chatId, string $text): bool|array
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
        $response = Http::post($this->apiUrl('sendMessage'), [
            'chat_id'      => $chatId,
            'text'         => "📊 *ស្ថិតិការទូទាត់*\nជ្រើសរយៈពេលដើម្បីមើល៖",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($this->mainStatsKeyboard()),
        ]);

        return $response->json() ?? [];
    }

    public function editToStatsMenu(string|int $chatId, int $messageId, string $text): array
    {
        $response = Http::post($this->apiUrl('editMessageText'), [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($this->mainStatsKeyboard()),
        ]);

        return $response->json() ?? [];
    }

    public function editToWeekMenu(string|int $chatId, int $messageId): array
    {
        $now = Carbon::now();
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

        $rows = array_chunk($weekButtons, 2);
        $rows[] = $this->backButton();

        $response = Http::post($this->apiUrl('editMessageText'), [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => "📆 *ជ្រើសសប្ដាហ៍ — " . $now->format('F Y') . "*",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => $rows,
            ]),
        ]);

        return $response->json() ?? [];
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

        $rows = array_chunk($monthButtons, 3);
        $rows[] = $this->backButton();

        $response = Http::post($this->apiUrl('editMessageText'), [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => "🗓 *ជ្រើសខែ — " . $now->format('Y') . "*",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => $rows,
            ]),
        ]);

        return $response->json() ?? [];
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

        $rows = array_chunk($yearButtons, 3);
        $rows[] = $this->backButton();

        $response = Http::post($this->apiUrl('editMessageText'), [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => "📊 *ជ្រើសឆ្នាំ*",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => $rows,
            ]),
        ]);

        return $response->json() ?? [];
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

        if (! empty($inlineKeyboard)) {
            $payload['reply_markup'] = json_encode([
                'inline_keyboard' => $inlineKeyboard,
            ]);
        } else {
            $payload['reply_markup'] = json_encode($this->mainStatsKeyboard());
        }

        $response = Http::post($this->apiUrl('editMessageText'), $payload);

        if (! $response->successful()) {
            Log::warning('Telegram editMessage failed', [
                'status'  => $response->status(),
                'body'    => $response->body(),
                'payload' => $payload,
            ]);
        }

        return $response->json() ?? [];
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): array
    {
        $payload = [
            'callback_query_id' => $callbackQueryId,
        ];

        if ($text !== '') {
            $payload['text'] = $text;
        }

        $response = Http::post($this->apiUrl('answerCallbackQuery'), $payload);

        return $response->json() ?? [];
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

        $response = Http::post($this->apiUrl('setWebhook'), $payload);

        return $response->json() ?? [];
    }

    public function webhookInfo(): array
    {
        $response = Http::get($this->apiUrl('getWebhookInfo'));

        return $response->json() ?? [];
    }

    public function deleteWebhook(): array
    {
        $response = Http::post($this->apiUrl('deleteWebhook'), [
            'drop_pending_updates' => true,
        ]);

        return $response->json() ?? [];
    }

    public function isAdmin(string|int $chatId, string|int $userId): bool
    {
        $response = Http::post($this->apiUrl('getChatMember'), [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ])->json();

        $status = $response['result']['status'] ?? '';

        return in_array($status, ['administrator', 'creator'], true);
    }

    // -------------------------------------------------------------------------
    // Internal HTTP
    // -------------------------------------------------------------------------

    private function request(string $method, array $payload): bool|array
    {
        $response = Http::post($this->apiUrl($method), $payload);

        if (! $response->successful()) {
            Log::warning('Telegram request failed', [
                'method'  => $method,
                'status'  => $response->status(),
                'body'    => $response->body(),
                'payload' => $payload,
            ]);
        }

        return $response->json() ?? false;
    }

    public function sendPhoto(
        string $chatId,
        string $photo,
        ?string $caption = null,
        array $extra = []
    ): array {
        $payload = array_merge([
            'chat_id' => $chatId,
            'photo' => $photo,
        ], $extra);
    
        if ($caption) {
            $payload['caption'] = $caption;
            $payload['parse_mode'] = 'Markdown';
        }
    
        return $this->request('sendPhoto', $payload);
    }
    
    public function sendPhotoUpload(
        string $chatId,
        string $filePath,
        ?string $caption = null,
        array $extra = []
    ): array {
        if (! file_exists($filePath)) {
            return [
                'ok' => false,
                'description' => 'Photo file does not exist: ' . $filePath,
            ];
        }
    
        $payload = array_merge([
            'chat_id' => $chatId,
            'photo' => new \CURLFile($filePath),
        ], $extra);
    
        if ($caption) {
            $payload['caption'] = $caption;
            $payload['parse_mode'] = 'Markdown';
        }
    
        return $this->requestMultipart('sendPhoto', $payload);
    }
    
    public function sendDocumentUpload(
        string $chatId,
        string $filePath,
        ?string $caption = null,
        array $extra = []
    ): array {
        if (! file_exists($filePath)) {
            return [
                'ok' => false,
                'description' => 'Document file does not exist: ' . $filePath,
            ];
        }
    
        $payload = array_merge([
            'chat_id' => $chatId,
            'document' => new \CURLFile($filePath),
        ], $extra);
    
        if ($caption) {
            $payload['caption'] = $caption;
            $payload['parse_mode'] = 'Markdown';
        }
    
        return $this->requestMultipart('sendDocument', $payload);
    }
    
    private function requestMultipart(string $method, array $payload): array
    {
        /**
         * Change this if your Telegram token config key is different.
         */
        $token = config('services.telegram.bot_token');
    
        $url = "https://api.telegram.org/bot{$token}/{$method}";
    
        $ch = curl_init();
    
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $payload,
        ]);
    
        $response = curl_exec($ch);
        $error = curl_error($ch);
    
        curl_close($ch);
    
        if ($error) {
            return [
                'ok' => false,
                'description' => $error,
            ];
        }
    
        return json_decode((string) $response, true) ?: [
            'ok' => false,
            'raw' => $response,
        ];
    }
}