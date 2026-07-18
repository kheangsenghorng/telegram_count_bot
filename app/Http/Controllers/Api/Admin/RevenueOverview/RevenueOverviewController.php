<?php

namespace App\Http\Controllers\Api\Admin\RevenueOverview;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Revenue Overview — package purchase revenue for the admin dashboard.
 *
 * GET /api/admin/revenue/overview
 *
 * Payload includes:
 *  - stats     (total_users / active_subscriptions / total_payments / revenue_this_month)
 *  - summary   (today / this_week / this_month / last_month / this_year / all_time)
 *  - growth    (day-over-day, week-over-week, month-over-month, year-over-year)
 *  - daily     (last 30 days)   — each with change_pct vs previous bucket
 *  - monthly   (last 12 months) — each with change_pct vs previous month
 *  - yearly    (last 5 years)   — each with change_pct vs previous year
 *  - by_method / by_package (this month)
 *
 * Caching: closed periods → long TTL; open periods + live payload → 60s.
 * Revenue timing: COALESCE(paid_at, created_at).
 */
class RevenueOverviewController extends Controller
{
    private const TZ = 'Asia/Phnom_Penh';

    // ── Adjust here if your schema differs ─────────────────────────────
    private const TABLE          = 'package_transactions';
    private const COL_PK         = 'packageTransactionsID';
    private const COL_AMOUNT     = 'amount';
    private const COL_CURRENCY   = 'currency';        // 'USD' | 'KHR'
    private const COL_STATUS     = 'status';
    private const COL_METHOD     = 'payment_method';  // bakong | aba | chipmong ...
    private const COL_PACKAGE_ID = 'package_id';
    private const COL_PAID_AT    = 'paid_at';
    private const COL_CREATED    = 'created_at';
    private const PAID_STATUSES  = ['success', 'SUCCESS', 'paid', 'PAID', 'completed'];

    // Dashboard stat tables
    private const USERS_TABLE      = 'users';
    private const SUBS_TABLE       = 'user_subscriptions';
    private const SUBS_STATUS_COL  = 'status';
    private const SUBS_ACTIVE      = ['active'];
    private const SUBS_EXPIRES_COL = 'ends_at';       // null = never expires (lifetime/unlimited)
    private const PAY_TABLE        = 'telegram_payments';

    private const CACHE_PREFIX = 'revenue';
    private const TTL_LIVE     = 60;                  // seconds
    private const TTL_CLOSED   = 60 * 60 * 24 * 30;   // closed periods never change

    public function overview()
    {
        $now = Carbon::now(self::TZ);

        $payload = Cache::remember(
            self::CACHE_PREFIX . ':overview:live',
            self::TTL_LIVE,
            function () use ($now) {
                $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
                $lastMonthEnd   = $now->copy()->subMonthNoOverflow()->endOfMonth();

                return [
                    'stats'   => $this->dashboardStats($now),
                    'summary' => [
                        'today'      => $this->rangeTotals($now->copy()->startOfDay(), $now),
                        'this_week'  => $this->rangeTotals($now->copy()->startOfWeek(), $now),
                        'this_month' => $this->rangeTotals($now->copy()->startOfMonth(), $now),
                        'last_month' => $this->rangeTotals($lastMonthStart, $lastMonthEnd),
                        'this_year'  => $this->rangeTotals($now->copy()->startOfYear(), $now),
                        'all_time'   => $this->rangeTotals(null, null),
                    ],
                    'growth'       => $this->growth($now),
                    'daily'        => $this->withChange($this->dailySeries($now, 30)),
                    'monthly'      => $this->withChange($this->monthlySeries($now, 12)),
                    'yearly'       => $this->withChange($this->yearlySeries($now, 5)),
                    'by_method'    => $this->byMethod($now->copy()->startOfMonth(), $now),
                    'by_package'   => $this->byPackage($now->copy()->startOfMonth(), $now),
                    'generated_at' => $now->toIso8601String(),
                ];
            }
        );

        return response()->json($payload);
    }

    // ── Dashboard stats (four top cards) ────────────────────────────────

    private function dashboardStats(Carbon $now): array
    {
        $monthStart     = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd   = $now->copy()->subMonthNoOverflow()->endOfMonth();

        // Total users
        $totalUsers = DB::table(self::USERS_TABLE)->count();
        $usersThis  = $this->countBetween(self::USERS_TABLE, 'created_at', $monthStart, $now);
        $usersLast  = $this->countBetween(self::USERS_TABLE, 'created_at', $lastMonthStart, $lastMonthEnd);

        // Active subscriptions (active status + not expired; null ends_at = lifetime)
        $subsQuery = DB::table(self::SUBS_TABLE)
            ->whereIn(self::SUBS_STATUS_COL, self::SUBS_ACTIVE)
            ->where(function ($q) use ($now) {
                $q->whereNull(self::SUBS_EXPIRES_COL)
                  ->orWhere(self::SUBS_EXPIRES_COL, '>', $now->copy()->setTimezone(config('app.timezone')));
            });
        $activeSubs = $subsQuery->count();
        $subsThis   = $this->countBetween(self::SUBS_TABLE, 'created_at', $monthStart, $now);
        $subsLast   = $this->countBetween(self::SUBS_TABLE, 'created_at', $lastMonthStart, $lastMonthEnd);

        // Total payments (parsed telegram payment records)
        $totalPayments = DB::table(self::PAY_TABLE)->count();
        $payThis       = $this->countBetween(self::PAY_TABLE, 'created_at', $monthStart, $now);
        $payLast       = $this->countBetween(self::PAY_TABLE, 'created_at', $lastMonthStart, $lastMonthEnd);

        // Revenue this month vs last month
        $revThis = $this->rangeTotals($monthStart, $now);
        $revLast = $this->rangeTotals($lastMonthStart, $lastMonthEnd);

        return [
            'total_users' => [
                'value'          => $totalUsers,
                'new_this_month' => $usersThis,
                'change_pct'     => $this->pctChange($usersThis, $usersLast),
            ],
            'active_subscriptions' => [
                'value'          => $activeSubs,
                'new_this_month' => $subsThis,
                'change_pct'     => $this->pctChange($subsThis, $subsLast),
            ],
            'total_payments' => [
                'value'          => $totalPayments,
                'new_this_month' => $payThis,
                'change_pct'     => $this->pctChange($payThis, $payLast),
            ],
            'revenue_this_month' => [
                'usd'        => $revThis['usd'],
                'khr'        => $revThis['khr'],
                'count'      => $revThis['count'],
                'change_pct' => $this->pctChange($revThis['usd'], $revLast['usd']),
            ],
        ];
    }

    private function countBetween(string $table, string $col, Carbon $start, Carbon $end): int
    {
        return DB::table($table)
            ->where($col, '>=', $start->copy()->setTimezone(config('app.timezone')))
            ->where($col, '<=', $end->copy()->setTimezone(config('app.timezone')))
            ->count();
    }

    // ── Growth ──────────────────────────────────────────────────────────

    /**
     * Growth compares equal *elapsed* portions of each period, so the
     * numbers are fair mid-period (e.g. first 18 days of this month vs
     * first 18 days of last month — not vs last month's full total).
     */
    private function growth(Carbon $now): array
    {
        $dod = $this->comparePeriods(
            $now->copy()->startOfDay(), $now,
            $now->copy()->subDay()->startOfDay(), $now->copy()->subDay()
        );

        $wow = $this->comparePeriods(
            $now->copy()->startOfWeek(), $now,
            $now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()
        );

        $mom = $this->comparePeriods(
            $now->copy()->startOfMonth(), $now,
            $now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()
        );

        $yoy = $this->comparePeriods(
            $now->copy()->startOfYear(), $now,
            $now->copy()->subYear()->startOfYear(), $now->copy()->subYear()
        );

        return [
            'day_over_day'     => $dod,
            'week_over_week'   => $wow,
            'month_over_month' => $mom,
            'year_over_year'   => $yoy,
        ];
    }

    private function comparePeriods(Carbon $curStart, Carbon $curEnd, Carbon $prevStart, Carbon $prevEnd): array
    {
        $current  = $this->rangeTotals($curStart, $curEnd);
        $previous = $this->rangeTotals($prevStart, $prevEnd);

        return [
            'current'    => $current,
            'previous'   => $previous,
            'change_pct' => [
                'usd'   => $this->pctChange($current['usd'], $previous['usd']),
                'khr'   => $this->pctChange($current['khr'], $previous['khr']),
                'count' => $this->pctChange($current['count'], $previous['count']),
            ],
        ];
    }

    /**
     * Percentage change; null when there's no previous baseline
     * (frontend renders that as "New" instead of a fake +∞%).
     */
    private function pctChange(float|int $current, float|int $previous): ?float
    {
        if ($previous == 0) {
            return $current > 0 ? null : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Adds change_pct (usd/khr) to each series entry vs the previous entry.
     * First entry has null change_pct.
     */
    private function withChange(array $series): array
    {
        $prev = null;

        foreach ($series as $i => $entry) {
            $series[$i]['change_pct'] = $prev === null ? null : [
                'usd' => $this->pctChange($entry['usd'], $prev['usd']),
                'khr' => $this->pctChange($entry['khr'], $prev['khr']),
            ];
            $prev = $entry;
        }

        return $series;
    }

    // ── Shared query helpers ────────────────────────────────────────────

    private function timeExpr(string $alias = ''): string
    {
        $p = $alias !== '' ? $alias . '.' : '';

        return sprintf('COALESCE(%s%s, %s%s)', $p, self::COL_PAID_AT, $p, self::COL_CREATED);
    }

    private function baseQuery()
    {
        return DB::table(self::TABLE)
            ->whereIn(self::COL_STATUS, self::PAID_STATUSES);
    }

    private function applyRange($q, ?Carbon $start, ?Carbon $end, string $alias = '')
    {
        $expr = $this->timeExpr($alias);

        if ($start) {
            $q->whereRaw($expr . ' >= ?', [
                $start->copy()->setTimezone(config('app.timezone'))->toDateTimeString(),
            ]);
        }
        if ($end) {
            $q->whereRaw($expr . ' <= ?', [
                $end->copy()->setTimezone(config('app.timezone'))->toDateTimeString(),
            ]);
        }

        return $q;
    }

    private function rangeTotals(?Carbon $start, ?Carbon $end): array
    {
        $q = $this->applyRange($this->baseQuery(), $start, $end);

        $rows = $q->selectRaw(sprintf(
                'UPPER(%s) as currency, SUM(%s) as total, COUNT(*) as cnt',
                self::COL_CURRENCY,
                self::COL_AMOUNT
            ))
            ->groupBy('currency')
            ->get();

        $out = ['usd' => 0.0, 'khr' => 0.0, 'count' => 0];

        foreach ($rows as $row) {
            $out['count'] += (int) $row->cnt;
            if ($row->currency === 'USD') {
                $out['usd'] = round((float) $row->total, 2);
            } elseif ($row->currency === 'KHR') {
                $out['khr'] = round((float) $row->total, 2);
            }
        }

        return $out;
    }

    private function cachedBucket(string $key, bool $isCurrent, Carbon $start, Carbon $end): array
    {
        return Cache::remember(
            $key,
            $isCurrent ? self::TTL_LIVE : self::TTL_CLOSED,
            fn () => $this->rangeTotals($start, $end)
        );
    }

    // ── Series ──────────────────────────────────────────────────────────

    private function dailySeries(
        Carbon $now,
        int $days
    ): array {
        $series = [];
    
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $now
                ->copy()
                ->subDays($i);
    
            $isToday = $day->isSameDay($now);
    
            $start = $day
                ->copy()
                ->startOfDay();
    
            $end = $isToday
                ? $now->copy()
                : $day->copy()->endOfDay();
    
            /*
            |--------------------------------------------------------------------------
            | Daily Revenue
            |--------------------------------------------------------------------------
            */
            $totals = $this->cachedBucket(
                self::CACHE_PREFIX
                    . ':daily:'
                    . $day->format('Ymd'),
                $isToday,
                $start,
                $end
            );
    
            /*
            |--------------------------------------------------------------------------
            | Daily Users / Subscriptions / Payments
            |--------------------------------------------------------------------------
            */
            $metrics = $this->metricTotals(
                $start,
                $end
            );
    
            $series[] = [
                'date' => $day->format('Y-m-d'),
    
                'label' => $day->format('d'),
    
                // Revenue
                'usd' => $totals['usd'],
                'khr' => $totals['khr'],
    
                // Package purchase transactions
                'count' => $totals['count'],
    
                // New users on this day
                'total_users' =>
                    $metrics['total_users'],
    
                // Active subscriptions created on this day
                'active_subscriptions' =>
                    $metrics['active_subscriptions'],
    
                // Telegram payments received on this day
                'total_payments' =>
                    $metrics['total_payments'],
            ];
        }
    
        return $series;
    }

    private function monthlySeries(
        Carbon $now,
        int $months
    ): array {
        $series = [];
    
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = $now
                ->copy()
                ->subMonthsNoOverflow($i)
                ->startOfMonth();
    
            $isCurrent =
                $start->isSameMonth($now);
    
            $end = $isCurrent
                ? $now->copy()
                : $start->copy()->endOfMonth();
    
            $totals = $this->cachedBucket(
                self::CACHE_PREFIX
                    . ':monthly:'
                    . $start->format('Ym'),
                $isCurrent,
                $start,
                $end
            );
    
            $metrics = $this->metricTotals(
                $start,
                $end
            );
    
            $series[] = [
                'month' =>
                    $start->format('Y-m'),
    
                'label' =>
                    $start->format('M'),
    
                'usd' =>
                    $totals['usd'],
    
                'khr' =>
                    $totals['khr'],
    
                'count' =>
                    $totals['count'],
    
                'total_users' =>
                    $metrics['total_users'],
    
                'active_subscriptions' =>
                    $metrics['active_subscriptions'],
    
                'total_payments' =>
                    $metrics['total_payments'],
            ];
        }
    
        return $series;
    }

    private function yearlySeries(
        Carbon $now,
        int $years
    ): array {
        $series = [];
    
        for ($i = $years - 1; $i >= 0; $i--) {
            $start = $now
                ->copy()
                ->subYears($i)
                ->startOfYear();
    
            $isCurrent =
                $start->isSameYear($now);
    
            $end = $isCurrent
                ? $now->copy()
                : $start->copy()->endOfYear();
    
            $totals = $this->cachedBucket(
                self::CACHE_PREFIX
                    . ':yearly:'
                    . $start->format('Y'),
                $isCurrent,
                $start,
                $end
            );
    
            $metrics = $this->metricTotals(
                $start,
                $end
            );
    
            $series[] = [
                'year' =>
                    $start->format('Y'),
    
                'label' =>
                    $start->format('Y'),
    
                'usd' =>
                    $totals['usd'],
    
                'khr' =>
                    $totals['khr'],
    
                'count' =>
                    $totals['count'],
    
                'total_users' =>
                    $metrics['total_users'],
    
                'active_subscriptions' =>
                    $metrics['active_subscriptions'],
    
                'total_payments' =>
                    $metrics['total_payments'],
            ];
        }
    
        return $series;
    }

    // ── Breakdowns ──────────────────────────────────────────────────────

    private function byMethod(Carbon $start, Carbon $end): array
    {
        $q = $this->applyRange($this->baseQuery(), $start, $end);

        return $q->selectRaw(sprintf(
                '%s as method, UPPER(%s) as currency, SUM(%s) as total, COUNT(*) as cnt',
                self::COL_METHOD,
                self::COL_CURRENCY,
                self::COL_AMOUNT
            ))
            ->groupBy('method', 'currency')
            ->orderByDesc('total')
            ->get()
            ->groupBy('method')
            ->map(function ($rows, $method) {
                $usd = $rows->firstWhere('currency', 'USD');
                $khr = $rows->firstWhere('currency', 'KHR');
                return [
                    'method' => $method ?: 'unknown',
                    'usd'    => round((float) ($usd->total ?? 0), 2),
                    'khr'    => round((float) ($khr->total ?? 0), 2),
                    'count'  => (int) $rows->sum('cnt'),
                ];
            })
            ->values()
            ->all();
    }

    private function byPackage(Carbon $start, Carbon $end): array
    {
        $q = DB::table(self::TABLE . ' as t')
            ->leftJoin('packages as p', 'p.packagesID', '=', 't.' . self::COL_PACKAGE_ID)
            ->whereIn('t.' . self::COL_STATUS, self::PAID_STATUSES);

        $this->applyRange($q, $start, $end, 't');

        return $q->selectRaw(sprintf(
                't.%s as package_id, p.name as package_name, UPPER(t.%s) as currency, SUM(t.%s) as total, COUNT(*) as cnt',
                self::COL_PACKAGE_ID,
                self::COL_CURRENCY,
                self::COL_AMOUNT
            ))
            ->groupBy('package_id', 'package_name', 'currency')
            ->get()
            ->groupBy('package_id')
            ->map(function ($rows) {
                $usd = $rows->firstWhere('currency', 'USD');
                $khr = $rows->firstWhere('currency', 'KHR');
                return [
                    'package_id'   => $rows->first()->package_id,
                    'package_name' => $rows->first()->package_name ?? 'Deleted package',
                    'usd'          => round((float) ($usd->total ?? 0), 2),
                    'khr'          => round((float) ($khr->total ?? 0), 2),
                    'count'        => (int) $rows->sum('cnt'),
                ];
            })
            ->sortByDesc(fn ($p) => $p['usd'] + $p['khr'] / 4100)
            ->values()
            ->take(10)
            ->all();
    }

    private function metricTotals(Carbon $start, Carbon $end): array
{
    $dbStart = $start
        ->copy()
        ->setTimezone(config('app.timezone'));

    $dbEnd = $end
        ->copy()
        ->setTimezone(config('app.timezone'));

    /*
    |--------------------------------------------------------------------------
    | Users created during this period
    |--------------------------------------------------------------------------
    */
    $users = DB::table(self::USERS_TABLE)
        ->whereBetween('created_at', [
            $dbStart,
            $dbEnd,
        ])
        ->count();

    /*
    |--------------------------------------------------------------------------
    | Active subscriptions during this period
    |--------------------------------------------------------------------------
    |
    | This counts subscriptions created in the bucket that are active
    | and not expired.
    |
    */
    $activeSubscriptions = DB::table(self::SUBS_TABLE)
        ->whereBetween('created_at', [
            $dbStart,
            $dbEnd,
        ])
        ->whereIn(
            self::SUBS_STATUS_COL,
            self::SUBS_ACTIVE
        )
        ->where(function ($query) use ($dbEnd) {
            $query
                ->whereNull(self::SUBS_EXPIRES_COL)
                ->orWhere(
                    self::SUBS_EXPIRES_COL,
                    '>',
                    $dbEnd
                );
        })
        ->count();

    /*
    |--------------------------------------------------------------------------
    | Telegram payments during this period
    |--------------------------------------------------------------------------
    */
    $payments = DB::table(self::PAY_TABLE)
        ->whereBetween('created_at', [
            $dbStart,
            $dbEnd,
        ])
        ->count();

    return [
        'total_users' => $users,
        'active_subscriptions' => $activeSubscriptions,
        'total_payments' => $payments,
    ];
}
}