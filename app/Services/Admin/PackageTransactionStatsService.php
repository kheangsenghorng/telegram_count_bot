<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\PackageTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class PackageTransactionStatsService
{
    private const CACHE_KEY = 'admin:package-transactions:stats';

    private const CACHE_TTL_SECONDS = 60;

    /**
     * Build transaction dashboard statistics.
     */
    public function build(
        int $year,
        int $month
    ): array {
        return [
            'summary' => $this->summary(
                year: $year,
                month: $month
            ),

            'months' => $this->monthly(
                year: $year
            ),

            'weeks' => $this->weekly(
                year: $year,
                month: $month
            ),

            'years' => $this->yearly(
                selectedYear: $year
            ),

            'filters' => [
                'year' => $year,
                'month' => $month,
            ],

            'generated_at' => now(
                $this->timezone()
            )->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get cached statistics.
     */
    public function get(
        int $year,
        int $month
    ): array {
        return Cache::remember(
            $this->cacheKey(
                year: $year,
                month: $month
            ),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->build(
                year: $year,
                month: $month
            )
        );
    }

    /**
     * Summary for the selected month.
     */
    private function summary(
        int $year,
        int $month
    ): array {
        [$monthStart, $monthEnd] = $this->monthRange(
            year: $year,
            month: $month
        );

        $statusCounts = PackageTransaction::query()
            ->whereBetween(
                'created_at',
                [
                    $monthStart,
                    $monthEnd,
                ]
            )
            ->selectRaw(
                'status, COUNT(*) AS total'
            )
            ->groupBy('status')
            ->pluck(
                'total',
                'status'
            );

        $paid = PackageTransaction::query()
            ->where('status', 'paid')
            ->whereBetween(
                'paid_at',
                [
                    $monthStart,
                    $monthEnd,
                ]
            )
            ->selectRaw(
                '
                    currency,
                    COUNT(*) AS transaction_count,
                    COALESCE(SUM(amount), 0) AS total_amount
                '
            )
            ->groupBy('currency')
            ->get();

        return [
            'year' => $year,

            'month' => $month,

            'total_transactions' => (int) $statusCounts->sum(),

            'by_status' => [
                'pending' => (int) (
                    $statusCounts['pending']
                    ?? 0
                ),

                'paid' => (int) (
                    $statusCounts['paid']
                    ?? 0
                ),

                'expired' => (int) (
                    $statusCounts['expired']
                    ?? 0
                ),

                'failed' => (int) (
                    $statusCounts['failed']
                    ?? 0
                ),
            ],

            'revenue' => $this->formatRevenue(
                $paid
            ),
        ];
    }

    /**
     * Return all 12 months for the selected year.
     */
    private function monthly(
        int $year
    ): array {
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            [$start, $end] = $this->monthRange(
                year: $year,
                month: $month
            );

            $results = PackageTransaction::query()
                ->where('status', 'paid')
                ->whereBetween(
                    'paid_at',
                    [
                        $start,
                        $end,
                    ]
                )
                ->selectRaw(
                    '
                        currency,
                        COUNT(*) AS transaction_count,
                        COALESCE(SUM(amount), 0) AS total_amount
                    '
                )
                ->groupBy('currency')
                ->get();

            $months[] = [
                'month' => $month,

                'name' => CarbonImmutable::create(
                    year: $year,
                    month: $month,
                    day: 1,
                    timezone: $this->timezone()
                )->format('F'),

                'transactions' => (int) $results->sum(
                    'transaction_count'
                ),

                'revenue' => $this->formatRevenue(
                    $results
                ),
            ];
        }

        return [
            'year' => $year,

            'data' => $months,
        ];
    }

    /**
     * Return week-by-week statistics for the selected month.
     *
     * Week 1: days 1-7
     * Week 2: days 8-14
     * Week 3: days 15-21
     * Week 4: days 22-28
     * Week 5: days 29-end of month
     */
    private function weekly(
        int $year,
        int $month
    ): array {
        $timezone = $this->timezone();

        $monthStart = CarbonImmutable::create(
            year: $year,
            month: $month,
            day: 1,
            timezone: $timezone
        )->startOfDay();

        $monthEnd = $monthStart
            ->endOfMonth()
            ->endOfDay();

        $weeks = [];

        $weekNumber = 1;

        $weekStart = $monthStart;

        while (
            $weekStart->lessThanOrEqualTo(
                $monthEnd
            )
        ) {
            $weekEnd = $weekStart
                ->addDays(6)
                ->endOfDay();

            if (
                $weekEnd->greaterThan(
                    $monthEnd
                )
            ) {
                $weekEnd = $monthEnd;
            }

            $results = PackageTransaction::query()
                ->where('status', 'paid')
                ->whereBetween(
                    'paid_at',
                    [
                        $weekStart->utc(),
                        $weekEnd->utc(),
                    ]
                )
                ->selectRaw(
                    '
                        currency,
                        COUNT(*) AS transaction_count,
                        COALESCE(SUM(amount), 0) AS total_amount
                    '
                )
                ->groupBy('currency')
                ->get();

            $weeks[] = [
                'week' => $weekNumber,

                'date_from' => $weekStart->format(
                    'Y-m-d'
                ),

                'date_to' => $weekEnd->format(
                    'Y-m-d'
                ),

                'transactions' => (int) $results->sum(
                    'transaction_count'
                ),

                'revenue' => $this->formatRevenue(
                    $results
                ),
            ];

            $weekStart = $weekEnd
                ->addSecond()
                ->startOfDay();

            $weekNumber++;
        }

        return [
            'year' => $year,

            'month' => $month,

            'month_name' => $monthStart->format(
                'F'
            ),

            'data' => $weeks,
        ];
    }

    /**
     * Return statistics grouped by year.
     *
     * Includes the selected year even when it is in the future
     * and has no transactions.
     */
    private function yearly(
        int $selectedYear
    ): array {
        $firstTransaction = PackageTransaction::query()
            ->whereNotNull('paid_at')
            ->orderBy('paid_at')
            ->first();

        $currentYear = now(
            $this->timezone()
        )->year;

        if (! $firstTransaction) {
            return [
                [
                    'year' => $selectedYear,
                    'transactions' => 0,
                    'revenue' => [],
                ],
            ];
        }

        $firstYear = CarbonImmutable::parse(
            $firstTransaction->paid_at
        )
            ->timezone(
                $this->timezone()
            )
            ->year;

        $startYear = max(
            $currentYear,
            $selectedYear
        );

        $endYear = min(
            $firstYear,
            $selectedYear
        );

        $years = [];

        for (
            $year = $startYear;
            $year >= $endYear;
            $year--
        ) {
            [$start, $end] = $this->yearRange(
                year: $year
            );

            $results = PackageTransaction::query()
                ->where('status', 'paid')
                ->whereBetween(
                    'paid_at',
                    [
                        $start,
                        $end,
                    ]
                )
                ->selectRaw(
                    '
                        currency,
                        COUNT(*) AS transaction_count,
                        COALESCE(SUM(amount), 0) AS total_amount
                    '
                )
                ->groupBy('currency')
                ->get();

            $years[] = [
                'year' => $year,

                'transactions' => (int) $results->sum(
                    'transaction_count'
                ),

                'revenue' => $this->formatRevenue(
                    $results
                ),
            ];
        }

        return $years;
    }

    /**
     * Get UTC start and end of a month
     * based on the application timezone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function monthRange(
        int $year,
        int $month
    ): array {
        $start = CarbonImmutable::create(
            year: $year,
            month: $month,
            day: 1,
            timezone: $this->timezone()
        )
            ->startOfMonth()
            ->startOfDay();

        return [
            $start->utc(),

            $start
                ->endOfMonth()
                ->endOfDay()
                ->utc(),
        ];
    }

    /**
     * Get UTC start and end of a year
     * based on the application timezone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function yearRange(
        int $year
    ): array {
        $start = CarbonImmutable::create(
            year: $year,
            month: 1,
            day: 1,
            timezone: $this->timezone()
        )
            ->startOfYear()
            ->startOfDay();

        return [
            $start->utc(),

            $start
                ->endOfYear()
                ->endOfDay()
                ->utc(),
        ];
    }

    /**
     * Format revenue grouped by currency.
     */
    private function formatRevenue(
        $results
    ): array {
        return $results
            ->map(
                fn ($item): array => [
                    'currency' => $item->currency,

                    'amount' => (float) $item->total_amount,
                ]
            )
            ->values()
            ->all();
    }

    /**
     * Get application timezone.
     *
     * .env:
     * APP_TIMEZONE=Asia/Phnom_Penh
     */
    private function timezone(): string
    {
        return (string) config(
            'app.timezone',
            'UTC'
        );
    }

    /**
     * Build cache key for selected year and month.
     */
    private function cacheKey(
        int $year,
        int $month
    ): string {
        return sprintf(
            '%s:%d:%d',
            self::CACHE_KEY,
            $year,
            $month
        );
    }

    /**
     * Clear stats cache.
     */
    public function clearCache(): void
    {
        $now = now(
            $this->timezone()
        );

        for (
            $year = $now->year - 1;
            $year <= $now->year + 1;
            $year++
        ) {
            for (
                $month = 1;
                $month <= 12;
                $month++
            ) {
                Cache::forget(
                    $this->cacheKey(
                        year: $year,
                        month: $month
                    )
                );
            }
        }
    }
}

