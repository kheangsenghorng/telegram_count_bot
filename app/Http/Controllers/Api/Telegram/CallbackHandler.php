<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Models\TelegramGroup;
use App\Services\PaymentStatsService;
use App\Services\TelegramBotService;
use App\Constants\BotCallback;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class CallbackHandler
{
    public function __construct(
        protected TelegramBotService  $telegram,
        protected PaymentStatsService $stats,
        protected PackageHandler      $packageHandler,  // ← added
    ) {}

    public function handle(array $callback): JsonResponse
    {
        $callbackId = $callback['id'];
        $chatId     = (string) ($callback['message']['chat']['id'] ?? '');
        $messageId  = (int)    ($callback['message']['message_id'] ?? 0);
        $data       = $callback['data'] ?? '';
        $chatType   = $callback['message']['chat']['type'] ?? 'private';  // ← added

        $from        = $callback['from'] ?? [];
        $requestedBy = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        $requestedBy = $requestedBy ?: ($from['username'] ?? 'unknown');

        $this->telegram->answerCallbackQuery($callbackId);

        // ── Package buy callback ───────────────────────────────────────────────
        if (preg_match(BotCallback::PATTERN_PACKAGE_BUY, $data, $m)) {
            $this->packageHandler->handleBuyCallback(
                $chatId,
                $messageId,
                $m[1],
                $requestedBy,
                $chatType,
            );
            return response()->json(['ok' => true]);
        }

        // ── Navigation callbacks — no group needed ────────────────────────────
        match ($data) {
            BotCallback::STATS_WEEK => $this->telegram->editToWeekMenu($chatId, $messageId),
            BotCallback::STATS_BACK => $this->telegram->editToStatsMenu(
                $chatId, $messageId,
                "📊 *ស្ថិតិការទូទាត់*\nជ្រើសរយៈពេលដើម្បីមើល៖"
            ),
            default => null,
        };

        if (in_array($data, [BotCallback::STATS_WEEK, BotCallback::STATS_BACK], true)) {
            return response()->json(['ok' => true]);
        }

        // ── Month / Year sub-menus ────────────────────────────────────────────
        if ($data === BotCallback::STATS_MONTH) {
            $group = $this->findConnectedGroup($chatId);
            if (! $group) {
                $this->telegram->editMessage($chatId, $messageId, '❌ Group not registered. Please use /connect first.');
                return response()->json(['ok' => true]);
            }
            $this->telegram->editToMonthMenu($chatId, $messageId, $this->stats->monthsWithData($group->telegramGroupsID));
            return response()->json(['ok' => true]);
        }

        if ($data === BotCallback::STATS_YEAR) {
            $group = $this->findConnectedGroup($chatId);
            if (! $group) {
                $this->telegram->editMessage($chatId, $messageId, '❌ Group not registered. Please use /connect first.');
                return response()->json(['ok' => true]);
            }

            $yearsWithData = $this->stats->yearsWithData($group->telegramGroupsID);
            if (count($yearsWithData) === 1 && $yearsWithData[0] === Carbon::now()->year) {
                $text = $this->stats->year($group->telegramGroupsID, $requestedBy);
                $this->telegram->editMessage($chatId, $messageId, $text);
            } else {
                $this->telegram->editToYearMenu($chatId, $messageId, $yearsWithData);
            }
            return response()->json(['ok' => true]);
        }

        // ── All stat data callbacks ───────────────────────────────────────────
        $group = $this->findConnectedGroup($chatId);
        if (! $group) {
            $this->telegram->editMessage($chatId, $messageId, '❌ Group not registered. Please use /connect first.');
            return response()->json(['ok' => true]);
        }

        $uuid = $group->telegramGroupsID;

        $text = match (true) {
            $data === BotCallback::STATS_DAY
                => $this->stats->day($uuid, $requestedBy),

            (bool) preg_match(BotCallback::PATTERN_WEEK, $data, $m)
                => $this->stats->weekByNumber($uuid, (int) $m[1], $requestedBy),

            (bool) preg_match(BotCallback::PATTERN_MONTH, $data, $m)
                => $this->stats->monthByNumber($uuid, (int) $m[1], $requestedBy),

            (bool) preg_match(BotCallback::PATTERN_YEAR, $data, $m)
                => $this->stats->yearByNumber($uuid, (int) $m[1], $requestedBy),

            default => null,
        };

        if ($text !== null) {
            $this->telegram->editMessage($chatId, $messageId, $text);
        }

        return response()->json(['ok' => true]);
    }

    private function findConnectedGroup(string $chatId): ?TelegramGroup
    {
        return TelegramGroup::where('group_id', $chatId)
            ->where('status', 'connected')
            ->first();
    }
}