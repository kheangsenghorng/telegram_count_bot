<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TelegramBotService
{
    private function token(): ?string
    {
        return config('services.telegram.bot_token');
    }

    private function apiUrl(string $method): string
    {
        return "https://api.telegram.org/bot" . $this->token() . "/" . $method;
    }

    public function sendMessage($chatId, string $text)
    {
        if (!$this->token() || !$chatId) {
            return false;
        }

        return Http::post($this->apiUrl('sendMessage'), [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }

    public function setWebhook(): array
    {
        $webhookUrl = rtrim(config('app.url'), '/') . '/api/telegram/webhook';

        return Http::post($this->apiUrl('setWebhook'), [
            'url' => $webhookUrl,
            'drop_pending_updates' => true,
        ])->json();
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
}