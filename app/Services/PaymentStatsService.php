<?php

namespace App\Services;

use App\Helpers\KhmerDateFormatter;
use App\Models\TelegramPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentStatsService
{
    /*
    |--------------------------------------------------------------------------
    | Cache TTLs (seconds)
    |--------------------------------------------------------------------------
    | LIVE   — period includes "now", data still changing  → 60s
    | CLOSED — period fully in the past, data is immutable → 1 day
    */
    private const TTL_LIVE   = 60;
    private const TTL_CLOSED = 86400;

    public static function statsKey(string $groupId, string $period): string
    {
        return "stats:{$groupId}:{$period}";
    }

    /**
     * Optional: call after a new payment is saved to make today's
     * numbers instantly fresh (otherwise self-heals within 60s).
     */
    public static function invalidateLive(string $groupId): void
    {
        $now = Carbon::now();

        Cache::forget(self::statsKey($groupId, 'day:' . $now->toDateString()));
        Cache::forget(self::statsKey($groupId, 'month:' . $now->format('Y-m')));
        Cache::forget(self::statsKey($groupId, 'year:' . $now->year));

        // Current week-of-month (1–4)
        $week = min((int) ceil($now->day / 7), 4);
        Cache::forget(self::statsKey($groupId, "week:{$now->format('Y-m')}:{$week}"));

        Cache::forget(self::statsKey($groupId, 'months_with_data:' . $now->year));
        Cache::forget(self::statsKey($groupId, 'years_with_data'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TODAY
    // ─────────────────────────────────────────────────────────────────────────
    public function day(string $groupId, string $requestedBy = 'unknown'): string
    {
        try {
            $date = KhmerDateFormatter::date(Carbon::today()); // ២៣ មិថុនា ២០២៥

            $totals = $this->cachedAggregate(
                $groupId,
                'day:' . Carbon::today()->toDateString(),
                Carbon::today(),
                Carbon::now(),
                isLive: true,
            );

            $this->logViewed('day', $requestedBy, $groupId, $totals, ['date' => $date]);

            return $this->render("📅 *ថ្ងៃនេះ — {$date}*", $totals);

        } catch (\Throwable $e) {
            $this->logFailed('day', $groupId, $e);

            return "❌ Failed to load today's stats. Please try again.";
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WEEK BY NUMBER (Week 1–4 of this month)
    // ─────────────────────────────────────────────────────────────────────────
    public function weekByNumber(string $groupId, int $weekNumber, string $requestedBy = 'unknown'): string
    {
        try {
            $now        = Carbon::now();
            $monthStart = $now->copy()->startOfMonth();

            $weekStart = $monthStart->copy()->addDays(($weekNumber - 1) * 7);
            $weekEnd   = ($weekNumber === 4)
                ? $now->copy()->endOfMonth()
                : $weekStart->copy()->addDays(6)->endOfDay();

            if ($weekStart->isAfter($now)) {
                $weekNumKh = KhmerDateFormatter::toKhmerNum($weekNumber);

                return "⏳ *សប្ដាហ៍ទី {$weekNumKh}* មិនទាន់ដល់ពេលនៅឡើយ។";
            }

            $isLive   = $weekEnd->isAfter($now);           // week still in progress?
            $queryEnd = $isLive ? $now->copy() : $weekEnd->copy();
            $range    = KhmerDateFormatter::date($weekStart) . ' – ' . KhmerDateFormatter::date($queryEnd);

            $totals = $this->cachedAggregate(
                $groupId,
                "week:{$now->format('Y-m')}:{$weekNumber}",
                $weekStart,
                $queryEnd,
                $isLive,
            );

            $weekNumKh = KhmerDateFormatter::toKhmerNum($weekNumber);

            $this->logViewed("week_{$weekNumber}", $requestedBy, $groupId, $totals, ['range' => $range]);

            return $this->render("📌 *សប្ដាហ៍ទី {$weekNumKh} — {$range}*", $totals);

        } catch (\Throwable $e) {
            $this->logFailed("week_{$weekNumber}", $groupId, $e);

            $weekNumKh = KhmerDateFormatter::toKhmerNum($weekNumber);

            return "❌ មិនអាចទាញស្ថិតិសប្ដាហ៍ទី {$weekNumKh} បានទេ។ សូមព្យាយាមម្តងទៀត។";
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // THIS MONTH
    // ─────────────────────────────────────────────────────────────────────────
    public function month(string $groupId, string $requestedBy = 'unknown'): string
    {
        try {
            $now   = Carbon::now();
            $label = KhmerDateFormatter::monthYear($now); // មិថុនា ២០២៥

            $totals = $this->cachedAggregate(
                $groupId,
                'month:' . $now->format('Y-m'),
                $now->copy()->startOfMonth(),
                $now,
                isLive: true,
            );

            $this->logViewed('month', $requestedBy, $groupId, $totals, ['month' => $now->format('F Y')]);

            return $this->render("🗓 *ខែនេះ — {$label}*", $totals);

        } catch (\Throwable $e) {
            $this->logFailed('month', $groupId, $e);

            return "❌ Failed to load monthly stats. Please try again.";
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // THIS YEAR
    // ─────────────────────────────────────────────────────────────────────────
    public function year(string $groupId, string $requestedBy = 'unknown'): string
    {
        try {
            $now = Carbon::now();

            $totals = $this->cachedAggregate(
                $groupId,
                'year:' . $now->year,
                $now->copy()->startOfYear(),
                $now,
                isLive: true,
            );

            $this->logViewed('year', $requestedBy, $groupId, $totals, ['year' => $now->year]);

            return $this->render("📊 *ឆ្នាំនេះ — {$now->year}*", $totals);

        } catch (\Throwable $e) {
            $this->logFailed('year', $groupId, $e);

            return "❌ Failed to load yearly stats. Please try again.";
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SPECIFIC MONTH  (e.g. month 3 = March of current year)
    // ─────────────────────────────────────────────────────────────────────────
    public function monthByNumber(string $groupId, int $monthNumber, string $requestedBy = 'unknown'): string
    {
        try {
            $now   = Carbon::now();
            $start = Carbon::create($now->year, $monthNumber, 1)->startOfMonth();

            $isLive = ($monthNumber === $now->month);
            $end    = $isLive ? $now->copy() : $start->copy()->endOfMonth();

            $label = KhmerDateFormatter::monthYear($start);

            $totals = $this->cachedAggregate(
                $groupId,
                'month:' . $start->format('Y-m'),
                $start,
                $end,
                $isLive,
            );

            $this->logViewed("month_{$monthNumber}", $requestedBy, $groupId, $totals, ['month' => $label]);

            return $this->render("🗓 *ខែ {$label}*", $totals);

        } catch (\Throwable $e) {
            $this->logFailed("month_{$monthNumber}", $groupId, $e);

            return "❌ Failed to load stats for month {$monthNumber}. Please try again.";
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SPECIFIC YEAR  (e.g. year 2024)
    // ─────────────────────────────────────────────────────────────────────────
    public function yearByNumber(string $groupId, int $year, string $requestedBy = 'unknown'): string
    {
        try {
            $now   = Carbon::now();
            $start = Carbon::create($year, 1, 1)->startOfYear();

            $isLive = ($year === $now->year);
            $end    = $isLive ? $now->copy() : $start->copy()->endOfYear();

            $totals = $this->cachedAggregate(
                $groupId,
                "year:{$year}",
                $start,
                $end,
                $isLive,
            );

            $this->logViewed("year_{$year}", $requestedBy, $groupId, $totals, ['year' => $year]);

            return $this->render("📊 *ឆ្នាំ {$year}*", $totals);

        } catch (\Throwable $e) {
            $this->logFailed("year_{$year}", $groupId, $e);

            return "❌ Failed to load stats for year {$year}. Please try again.";
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER — which months (1–12) in current year have at least 1 payment
    // ─────────────────────────────────────────────────────────────────────────
    public function monthsWithData(string $groupId): array
    {
        $year = Carbon::now()->year;

        return Cache::remember(
            self::statsKey($groupId, "months_with_data:{$year}"),
            self::TTL_LIVE,
            fn () => TelegramPayment::query()
                ->where('telegram_group_id', $groupId)
                ->whereYear('created_at', $year)
                ->selectRaw('MONTH(created_at) as m')
                ->groupBy('m')
                ->pluck('m')
                ->map(fn ($v) => (int) $v)
                ->toArray()
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER — which years have at least 1 payment (newest first)
    // ─────────────────────────────────────────────────────────────────────────
    public function yearsWithData(string $groupId): array
    {
        return Cache::remember(
            self::statsKey($groupId, 'years_with_data'),
            self::TTL_LIVE,
            fn () => TelegramPayment::query()
                ->where('telegram_group_id', $groupId)
                ->selectRaw('YEAR(created_at) as y')
                ->groupBy('y')
                ->orderByDesc('y')
                ->pluck('y')
                ->map(fn ($v) => (int) $v)
                ->toArray()
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cached DB aggregate
    // ─────────────────────────────────────────────────────────────────────────
    // LIVE periods   → 60s TTL (dashboard/bot hits MySQL max once per minute)
    // CLOSED periods → 1 day TTL (March 2025 never changes — no need to re-query)
    // ─────────────────────────────────────────────────────────────────────────
    private function cachedAggregate(
        string $groupId,
        string $period,
        Carbon $start,
        Carbon $end,
        bool $isLive,
    ): array {
        return Cache::remember(
            self::statsKey($groupId, $period),
            $isLive ? self::TTL_LIVE : self::TTL_CLOSED,
            fn () => $this->aggregate($start, $end, $groupId)
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DB aggregate — runs 1 SQL query, loads ZERO rows into memory
    // ─────────────────────────────────────────────────────────────────────────
    private function aggregate(Carbon $start, Carbon $end, string $groupId): array
    {
        $rows = TelegramPayment::query()
            ->whereBetween('created_at', [$start, $end])
            ->where('telegram_group_id', $groupId)
            ->select(
                DB::raw('COUNT(*) as total_count'),
                DB::raw("SUM(CASE WHEN currency = 'USD' THEN amount ELSE 0 END) as usd_total"),
                DB::raw("SUM(CASE WHEN currency = 'KHR' THEN amount ELSE 0 END) as khr_total"),
            )
            ->first();

        return [
            'count' => (int)   ($rows->total_count ?? 0),
            'usd'   => (float) ($rows->usd_total   ?? 0),
            'khr'   => (float) ($rows->khr_total   ?? 0),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Shared renderer — one place to change the message format
    // ─────────────────────────────────────────────────────────────────────────
    private function render(string $title, array $totals): string
    {
        return implode("\n", [
            $title,
            "━━━━━━━━━━━━━━━━━━━━━━",
            "🔢 ចំនួនប្រតិបត្តិការ : *{$totals['count']}*",
            "💵 សរុប USD          : *$ " . number_format($totals['usd'], 2) . "*",
            "💴 សរុប KHR          : *៛ " . number_format($totals['khr'], 0) . "*",
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Shared logging
    // ─────────────────────────────────────────────────────────────────────────
    private function logViewed(string $period, string $requestedBy, string $groupId, array $totals, array $extra = []): void
    {
        Log::channel('telegram_stats')->info('Stats viewed', array_merge([
            'period'       => $period,
            'requested_by' => $requestedBy,
            'group_id'     => $groupId,
            'count'        => $totals['count'],
            'usd_total'    => $totals['usd'],
            'khr_total'    => $totals['khr'],
        ], $extra));
    }

    private function logFailed(string $period, string $groupId, \Throwable $e): void
    {
        Log::channel('telegram_stats')->error('Stats query failed', [
            'period'   => $period,
            'group_id' => $groupId,
            'error'    => $e->getMessage(),
            'trace'    => $e->getTraceAsString(),
        ]);
    }
}