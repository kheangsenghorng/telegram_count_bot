<?php

namespace App\Http\Controllers\Api\Admin\Dashboard;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard Stats — the four top cards on the admin dashboard.
 *
 * GET /api/admin/dashboard/stats
 *
 * Returns totals + percentage change vs last month for:
 *  - total_users            (all registered users)
 *  - active_subscriptions   (currently active subscriptions)
 *  - total_payments         (parsed telegram payment records)
 *  - revenue_this_month     (successful package transactions, USD + KHR)
 *
 * Cached 60s — self-healing, no invalidation needed.
 */
class DashboardStatsController extends Controller
{
    private const TZ = 'Asia/Phnom_Penh';

    // ── Adjust here if your schema differs ─────────────────────────────
    private const USERS_TABLE = 'users';

    private const SUBS_TABLE       = 'user_subscriptions';
    private const SUBS_STATUS_COL  = 'status';           // set to null if no status column
    private const SUBS_ACTIVE      = ['active', 'ACTIVE'];
    private const SUBS_EXPIRES_COL = 'ends_at';          // null = never expires (lifetime/unlimited)

    private const PAY_TABLE = 'telegram_payments';

    private const TX_TABLE         = 'package_transactions';
    private const TX_AMOUNT        = 'amount';
    private const TX_CURRENCY      = 'currency';
    private const TX_STATUS        = 'status';
    private const TX_PAID_STATUSES = ['success', 'SUCCESS', 'paid', 'PAID', 'completed'];
    private const TX_PAID_AT       = 'paid_at';
    private const TX_CREATED       = 'created_at';

    private const CACHE_KEY = 'dashboard:stats';
    private const TTL       = 60;

    public function stats()
    {
        $payload = Cache::remember(self::CACHE_KEY, self::TTL, function () {
            $now            = Carbon::now(self::TZ);
            $monthStart     = $now->copy()->startOfMonth();
            $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
            $lastMonthEnd   = $now->copy()->subMonthNoOverflow()->endOfMonth();

            // ── Total users ──────────────────────────────────────────
            $totalUsers    = DB::table(self::USERS_TABLE)->count();
            $usersThis     = $this->countBetween(self::USERS_TABLE, 'created_at', $monthStart, $now);
            $usersLast     = $this->countBetween(self::USERS_TABLE, 'created_at', $lastMonthStart, $lastMonthEnd);

            // ── Active subscriptions ─────────────────────────────────
            $subsQuery = DB::table(self::SUBS_TABLE);
            if (self::SUBS_STATUS_COL !== null) {
                $subsQuery->whereIn(self::SUBS_STATUS_COL, self::SUBS_ACTIVE);
            }
            $subsQuery->where(function ($q) {
                $q->whereNull(self::SUBS_EXPIRES_COL)
                  ->orWhere(self::SUBS_EXPIRES_COL, '>', Carbon::now(self::TZ)->setTimezone(config('app.timezone')));
            });
            $activeSubs = $subsQuery->count();

            $subsThis = $this->countBetween(self::SUBS_TABLE, 'created_at', $monthStart, $now);
            $subsLast = $this->countBetween(self::SUBS_TABLE, 'created_at', $lastMonthStart, $lastMonthEnd);

            // ── Total payments (parsed telegram payment records) ─────
            $totalPayments = DB::table(self::PAY_TABLE)->count();
            $payThis       = $this->countBetween(self::PAY_TABLE, 'created_at', $monthStart, $now);
            $payLast       = $this->countBetween(self::PAY_TABLE, 'created_at', $lastMonthStart, $lastMonthEnd);

            // ── Revenue this month vs last month ─────────────────────
            $revThis = $this->revenueBetween($monthStart, $now);
            $revLast = $this->revenueBetween($lastMonthStart, $lastMonthEnd);

            return [
                'total_users' => [
                    'value'      => $totalUsers,
                    'new_this_month' => $usersThis,
                    'change_pct' => $this->pctChange($usersThis, $usersLast),
                ],
                'active_subscriptions' => [
                    'value'      => $activeSubs,
                    'new_this_month' => $subsThis,
                    'change_pct' => $this->pctChange($subsThis, $subsLast),
                ],
                'total_payments' => [
                    'value'      => $totalPayments,
                    'new_this_month' => $payThis,
                    'change_pct' => $this->pctChange($payThis, $payLast),
                ],
                'revenue_this_month' => [
                    'usd'        => $revThis['usd'],
                    'khr'        => $revThis['khr'],
                    'count'      => $revThis['count'],
                    'change_pct' => $this->pctChange($revThis['usd'], $revLast['usd']),
                ],
                'generated_at' => $now->toIso8601String(),
            ];
        });

        return response()->json($payload);
    }

    // ────────────────────────────────────────────────────────────────────

    private function countBetween(string $table, string $col, Carbon $start, Carbon $end): int
    {
        return DB::table($table)
            ->where($col, '>=', $start->copy()->setTimezone(config('app.timezone')))
            ->where($col, '<=', $end->copy()->setTimezone(config('app.timezone')))
            ->count();
    }

    private function revenueBetween(Carbon $start, Carbon $end): array
    {
        $expr = sprintf('COALESCE(%s, %s)', self::TX_PAID_AT, self::TX_CREATED);

        $rows = DB::table(self::TX_TABLE)
            ->whereIn(self::TX_STATUS, self::TX_PAID_STATUSES)
            ->whereRaw($expr . ' >= ?', [$start->copy()->setTimezone(config('app.timezone'))->toDateTimeString()])
            ->whereRaw($expr . ' <= ?', [$end->copy()->setTimezone(config('app.timezone'))->toDateTimeString()])
            ->selectRaw(sprintf(
                'UPPER(%s) as currency, SUM(%s) as total, COUNT(*) as cnt',
                self::TX_CURRENCY,
                self::TX_AMOUNT
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

    /**
     * Percentage change, null when there's no previous baseline.
     */
    private function pctChange(float|int $current, float|int $previous): ?float
    {
        if ($previous == 0) {
            return $current > 0 ? null : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}