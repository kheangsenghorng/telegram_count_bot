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

    public function sendMessage(string|int $chatId, string $text, array $extra = []): bool|array
    {
        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ];
    
        foreach ($extra as $key => $value) {
            $payload[$key] = ($key === 'reply_markup' && is_array($value))
                ? json_encode($value)
                : $value;
        }
    
        return $this->request('sendMessage', $payload);
    }

    public function sendMarkdown(string|int $chatId, string $text): bool|array
    {
        return $this->sendMessage($chatId, $text);
    }

    public function sendMainMenu(string|int $chatId, string $text): bool|array
    {
        return $this->sendMessage($chatId, $text, [
            'reply_markup' => [
                'keyboard' => [
                    [['text' => '🆕 Package'],        ['text' => '🔑 My Tokens']],
                    [['text' => '🌐 Domains'],         ['text' => '💬 Support']],
                    [['text' => '🔒 Privacy Policy'],  ['text' => '📜 Terms of Service']],
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

        return $response->json();
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

        return $response->json();
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

            $weekEnd    = ($week === 4)
                ? $now->copy()->endOfMonth()
                : $weekStart->copy()->addDays(6)->endOfDay();
            $displayEnd = $weekEnd->isAfter($now) ? $now->copy() : $weekEnd->copy();
            $range      = $weekStart->format('d M') . '–' . $displayEnd->format('d M');

            $weekButtons[] = [
                'text'          => "សប្ដាហ៍ទី {$week} ({$range})",
                'callback_data' => BotCallback::STATS_WEEK_PREFIX . $week,
            ];
        }

        $rows   = array_chunk($weekButtons, 2);
        $rows[] = $this->backButton();

        $response = Http::post($this->apiUrl('editMessageText'), [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => "📆 *ជ្រើសសប្ដាហ៍ — " . $now->format('F Y') . "*",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);

        return $response->json();
    }

    public function editToMonthMenu(string|int $chatId, int $messageId, array $monthsWithData): array
    {
        $now = Carbon::now();

        $monthButtons = [];
        for ($m = 1; $m <= 12; $m++) {
            if ($m > $now->month) {
                continue;
            }

            if ($m !== $now->month && ! in_array($m, $monthsWithData, true)) {
                continue;
            }

            $label          = Carbon::create($now->year, $m)->translatedFormat('F');
            $monthButtons[] = [
                'text'          => "🗓 {$label}",
                'callback_data' => BotCallback::STATS_MONTH_PREFIX . $m,
            ];
        }

        $rows   = array_chunk($monthButtons, 3);
        $rows[] = $this->backButton();

        $response = Http::post($this->apiUrl('editMessageText'), [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => "🗓 *ជ្រើសខែ — " . $now->format('Y') . "*",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);

        return $response->json();
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

        $response = Http::post($this->apiUrl('editMessageText'), [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => "📊 *ជ្រើសឆ្នាំ*",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);

        return $response->json();
    }

    // ── editMessage now accepts optional inline keyboard ──────────────────────
    public function editMessage(
        string|int $chatId,
        int        $messageId,
        string     $text,
        array      $inlineKeyboard = []   // ← added
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

        return Http::post($this->apiUrl('editMessageText'), $payload)->json();
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): array
    {
        $response = Http::post($this->apiUrl('answerCallbackQuery'), [
            'callback_query_id' => $callbackQueryId,
            'text'              => $text,
        ]);

        return $response->json();
    }

    // -------------------------------------------------------------------------
    // Keyboards
    // -------------------------------------------------------------------------

    private function mainStatsKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '📅 ថ្ងៃនេះ',   'callback_data' => BotCallback::STATS_DAY],
                    ['text' => '📆 សប្ដាហ៍នេះ', 'callback_data' => BotCallback::STATS_WEEK],
                ],
                [
                    ['text' => '🗓 ខែនេះ',     'callback_data' => BotCallback::STATS_MONTH],
                    ['text' => '📊 ឆ្នាំនេះ',  'callback_data' => BotCallback::STATS_YEAR],
                ],
            ],
        ];
    }

    private function backButton(): array
    {
        return [
            ['text' => '◀️ ត្រឡប់ក្រោយ', 'callback_data' => BotCallback::STATS_BACK],
        ];
    }

    // -------------------------------------------------------------------------
    // Webhook management
    // -------------------------------------------------------------------------

    public function setWebhook(string $url = '', string $secretToken = ''): array
    {
        $url = $url ?: rtrim(config('app.url'), '/') . '/api/telegram/webhook';

        $payload = ['url' => $url, 'drop_pending_updates' => true];

        if ($secretToken) {
            $payload['secret_token'] = $secretToken;
        }

        return Http::post($this->apiUrl('setWebhook'), $payload)->json();
    }

    public function webhookInfo(): array
    {
        return Http::get($this->apiUrl('getWebhookInfo'))->json();
    }

    public function deleteWebhook(): array
    {
        return Http::post($this->apiUrl('deleteWebhook'), [
            'drop_pending_updates' => true,
        ])->json();
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
        return $response->json() ?? false;
    }
}