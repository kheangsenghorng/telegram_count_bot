<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\PackageTransaction;

use App\Http\Controllers\Controller;
use App\Models\PackageTransaction;
use App\Services\Admin\PackageTransactionStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

final class PackageTransactionController extends Controller
{
    private const STATS_CACHE_KEY = 'admin:package-transactions:stats';

    private const STATS_TTL_SECONDS = 60;

    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    private const STATUSES = [
        'pending',
        'paid',
        'expired',
        'failed',
    ];

    private const GATEWAYS = [
        'bakong_khqr',
        'aba_payway',
    ];

    private const PERIODS = [
        'today',
        'week',
        'month',
        'year',
    ];

    /**
     * GET /api/admin/transactions
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status' => [
                    'nullable',
                    Rule::in(self::STATUSES),
                ],

                'gateway' => [
                    'nullable',
                    Rule::in(self::GATEWAYS),
                ],

                'period' => [
                    'nullable',
                    Rule::in(self::PERIODS),
                ],

                'user_id' => [
                    'nullable',
                    'string',
                    'max:100',
                ],

                'package_id' => [
                    'nullable',
                    'string',
                    'max:100',
                ],

                'date_from' => [
                    'nullable',
                    'date_format:Y-m-d',
                ],

                'date_to' => [
                    'nullable',
                    'date_format:Y-m-d',
                    'after_or_equal:date_from',
                ],

                'search' => [
                    'nullable',
                    'string',
                    'max:255',
                ],

                'per_page' => [
                    'nullable',
                    'integer',
                    'min:1',
                    'max:' . self::MAX_PER_PAGE,
                ],
            ]);

            $query = PackageTransaction::query()
                ->select([
                    'packageTransactionsID',
                    'user_id',
                    'subscription_id',
                    'package_id',
                    'amount',
                    'currency',
                    'payment_method',
                    'status',
                    'gateway',
                    'gateway_status',
                    'merchant_ref_no',
                    'aba_tran_id',
                    'external_transaction_id',
                    'checkout_url',
                    'paid_at',
                    'expires_at',
                    'created_at',
                    'updated_at',
                ])
                ->with([
                    'user:uuid,first_name,last_name,email,telegram_id,telegram_username,status',
                    'package:packagesID,name,price,billing_cycle,payment_limit,group_limit,status',
                ]);

            $this->applyFilters(
                query: $query,
                filters: $validated
            );

            $transactions = $query
                ->orderByDesc('created_at')
                ->paginate(
                    perPage: (int) (
                        $validated['per_page']
                        ?? self::DEFAULT_PER_PAGE
                    )
                )
                ->withQueryString();

            return response()->json([
                'success' => true,

                'data' => $transactions->items(),

                'meta' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                    'from' => $transactions->firstItem(),
                    'to' => $transactions->lastItem(),
                    'has_more_pages' => $transactions->hasMorePages(),
                ],

                'filters' => [
                    'status' => $validated['status'] ?? null,
                    'gateway' => $validated['gateway'] ?? null,
                    'period' => $validated['period'] ?? null,
                    'user_id' => $validated['user_id'] ?? null,
                    'package_id' => $validated['package_id'] ?? null,
                    'date_from' => $validated['date_from'] ?? null,
                    'date_to' => $validated['date_to'] ?? null,
                    'search' => $validated['search'] ?? null,
                ],
            ]);
        } catch (Throwable $e) {
            Log::error(
                'Admin package transaction index failed.',
                [
                    'filters' => $request->only([
                        'status',
                        'gateway',
                        'period',
                        'user_id',
                        'package_id',
                        'date_from',
                        'date_to',
                        'search',
                        'per_page',
                    ]),

                    'exception' => $e,
                ]
            );

            return $this->serverError(
                'Unable to fetch transactions.'
            );
        }
    }

    /**
     * GET /api/admin/transactions/{id}
     */
    public function show(string $id): JsonResponse
    {
        try {
            $transaction = PackageTransaction::query()
                ->with([
                    'user',
                    'package',
                    'subscription',
                ])
                ->where(
                    'packageTransactionsID',
                    $id
                )
                ->first();

            if (! $transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $transaction,
            ]);
        } catch (Throwable $e) {
            Log::error(
                'Admin package transaction show failed.',
                [
                    'package_transaction_id' => $id,
                    'exception' => $e,
                ]
            );

            return $this->serverError(
                'Unable to fetch transaction.'
            );
        }
    }

    /**
     * GET /api/admin/transactions/stats
     *
     * Examples:
     *
     * /api/admin/transactions/stats
     * /api/admin/transactions/stats?year=2026
     * /api/admin/transactions/stats?year=2026&month=7
     */
    public function stats(
        Request $request,
        PackageTransactionStatsService $statsService
    ): JsonResponse {
        try {
            $validated = $request->validate([
                'year' => [
                    'nullable',
                    'integer',
                    'min:2000',
                    'max:2100',
                ],

                'month' => [
                    'nullable',
                    'integer',
                    'min:1',
                    'max:12',
                ],
            ]);

            $now = now(
                $this->timezone()
            );

            $year = (int) (
                $validated['year']
                ?? $now->year
            );

            $month = (int) (
                $validated['month']
                ?? $now->month
            );

            $cacheKey = sprintf(
                '%s:%d:%d',
                self::STATS_CACHE_KEY,
                $year,
                $month
            );

            $data = Cache::remember(
                $cacheKey,
                self::STATS_TTL_SECONDS,
                fn (): array => $statsService->build(
                    year: $year,
                    month: $month
                )
            );

            return response()->json([
                'success' => true,

                'data' => $data,
            ]);
        } catch (Throwable $e) {
            Log::error(
                'Admin package transaction stats failed.',
                [
                    'year' => $request->input('year'),
                    'month' => $request->input('month'),
                    'exception' => $e,
                ]
            );

            return $this->serverError(
                'Unable to fetch transaction stats.'
            );
        }
    }

    /**
     * PATCH /api/admin/transactions/expire
     */

//  public function expireById(
//         string $id,
//         PackageTransactionStatsService $statsService
//     ): JsonResponse {
//         try {
//             $transaction = PackageTransaction::query()
//                 ->where('packageTransactionsID', $id)
//                 ->first();

//             if (! $transaction) {
//                 return response()->json([
//                     'success' => false,
//                     'message' => 'Transaction not found.',
//                 ], 404);
//             }

//             if ($transaction->status !== 'pending') {
//                 return response()->json([
//                     'success' => false,
//                     'message' => sprintf(
//                         'Only pending transactions can be expired. Current status: %s.',
//                         $transaction->status
//                     ),
//                 ], 422);
//             }

//             $transaction->update([
//                 'status' => 'expired',
//             ]);

//             /*
//             |--------------------------------------------------------------------------
//             | Clear stats cache
//             |--------------------------------------------------------------------------
//             |
//             | The transaction status changed, so dashboard statistics
//             | should be recalculated on the next request.
//             |
//             */
//             $statsService->clearCache();

//             return response()->json([
//                 'success' => true,
//                 'message' => 'Transaction expired successfully.',

//                 'data' => [
//                     'packageTransactionsID' => $transaction->packageTransactionsID,
//                     'previous_status' => 'pending',
//                     'status' => 'expired',
//                     'updated_at' => $transaction->updated_at,
//                 ],
//             ]);
//         } catch (Throwable $e) {
//             Log::error(
//                 'Admin package transaction expire failed.',
//                 [
//                     'package_transaction_id' => $id,
//                     'exception' => $e,
//                 ]
//             );

//             return $this->serverError(
//                 'Unable to expire transaction.'
//             );
//         }
//     }


/**
 * PATCH /api/admin/transactions/expire
 *
 * Body:
 * {
 *     "id": "PACKAGE_TRANSACTION_ID"
 * }
 */
public function expire(
    Request $request,
    PackageTransactionStatsService $statsService
): JsonResponse {
    try {
        $validated = $request->validate([
            'id' => [
                'required',
                'string',
            ],
        ]);

        $id = $validated['id'];

        $transaction = PackageTransaction::query()
            ->where(
                'packageTransactionsID',
                $id
            )
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Transaction not found
        |--------------------------------------------------------------------------
        */
        if (! $transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Already expired
        |--------------------------------------------------------------------------
        |
        | Calling this endpoint multiple times is safe.
        |
        | expired -> expired = success
        |
        */
        if ($transaction->status === 'expired') {
            return response()->json([
                'success' => true,

                'message' => 'Transaction is already expired.',

                'data' => [
                    'packageTransactionsID' => $transaction->packageTransactionsID,
                    'status' => $transaction->status,
                    'updated_at' => $transaction->updated_at,
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Only pending transactions can become expired
        |--------------------------------------------------------------------------
        |
        | paid   -> expired = blocked
        | failed -> expired = blocked
        |
        */
        if ($transaction->status !== 'pending') {
            return response()->json([
                'success' => false,

                'message' => sprintf(
                    'Only pending transactions can be expired. Current status: %s.',
                    $transaction->status
                ),

                'data' => [
                    'packageTransactionsID' => $transaction->packageTransactionsID,
                    'status' => $transaction->status,
                ],
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Expire transaction
        |--------------------------------------------------------------------------
        */
        $transaction->update([
            'status' => 'expired',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Clear cached dashboard statistics
        |--------------------------------------------------------------------------
        */
        $statsService->clearCache();

        /*
        |--------------------------------------------------------------------------
        | Success response
        |--------------------------------------------------------------------------
        */
        return response()->json([
            'success' => true,

            'message' => 'Transaction expired successfully.',

            'data' => [
                'packageTransactionsID' => $transaction->packageTransactionsID,
                'previous_status' => 'pending',
                'status' => $transaction->status,
                'updated_at' => $transaction->updated_at,
            ],
        ]);
    } catch (Throwable $e) {
        Log::error(
            'Admin package transaction expire failed.',
            [
                'package_transaction_id' => $request->input('id'),
                'exception' => $e,
            ]
        );

        return $this->serverError(
            'Unable to expire transaction.'
        );
    }
}



    /**
     * Apply all transaction filters.
     *
     * @param Builder<PackageTransaction> $query
     */
    private function applyFilters(
        Builder $query,
        array $filters
    ): void {
        $query
            ->when(
                ! empty($filters['status']),
                fn (Builder $query) => $query->where(
                    'status',
                    $filters['status']
                )
            )
            ->when(
                ! empty($filters['gateway']),
                fn (Builder $query) => $query->where(
                    'gateway',
                    $filters['gateway']
                )
            )
            ->when(
                ! empty($filters['user_id']),
                fn (Builder $query) => $query->where(
                    'user_id',
                    $filters['user_id']
                )
            )
            ->when(
                ! empty($filters['package_id']),
                fn (Builder $query) => $query->where(
                    'package_id',
                    $filters['package_id']
                )
            );

        $this->applyDateFilter(
            query: $query,
            filters: $filters
        );

        $this->applySearchFilter(
            query: $query,
            search: $filters['search'] ?? null
        );
    }

    /**
     * Apply period or custom date filters.
     *
     * period takes priority over date_from and date_to.
     *
     * @param Builder<PackageTransaction> $query
     */
    private function applyDateFilter(
        Builder $query,
        array $filters
    ): void {
        if (! empty($filters['period'])) {
            [$start, $end] = $this->resolvePeriodRange(
                $filters['period']
            );

            $query->whereBetween(
                'created_at',
                [
                    $start->utc(),
                    $end->utc(),
                ]
            );

            return;
        }

        if (! empty($filters['date_from'])) {
            $dateFrom = CarbonImmutable::createFromFormat(
                'Y-m-d',
                $filters['date_from'],
                $this->timezone()
            )
                ->startOfDay()
                ->utc();

            $query->where(
                'created_at',
                '>=',
                $dateFrom
            );
        }

        if (! empty($filters['date_to'])) {
            $dateTo = CarbonImmutable::createFromFormat(
                'Y-m-d',
                $filters['date_to'],
                $this->timezone()
            )
                ->endOfDay()
                ->utc();

            $query->where(
                'created_at',
                '<=',
                $dateTo
            );
        }
    }

    /**
     * Resolve predefined date period.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolvePeriodRange(
        string $period
    ): array {
        $now = CarbonImmutable::now(
            $this->timezone()
        );

        return match ($period) {
            'today' => [
                $now->startOfDay(),
                $now->endOfDay(),
            ],

            'week' => [
                $now->startOfWeek(),
                $now->endOfWeek(),
            ],

            'month' => [
                $now->startOfMonth(),
                $now->endOfMonth(),
            ],

            'year' => [
                $now->startOfYear(),
                $now->endOfYear(),
            ],

            default => [
                $now->startOfDay(),
                $now->endOfDay(),
            ],
        };
    }

    /**
     * Apply transaction search.
     *
     * @param Builder<PackageTransaction> $query
     */
    private function applySearchFilter(
        Builder $query,
        ?string $search
    ): void {
        $search = trim(
            (string) $search
        );

        if ($search === '') {
            return;
        }

        $query->where(
            function (Builder $query) use ($search): void {
                $query
                    ->where(
                        'packageTransactionsID',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'external_transaction_id',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'merchant_ref_no',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'aba_tran_id',
                        'like',
                        "%{$search}%"
                    );
            }
        );
    }

    /**
     * Clear stats cache for recent years/months.
     */
    private function clearStatsCache(): void
    {
        $now = now(
            $this->timezone()
        );

        for (
            $year = $now->year - 1;
            $year <= $now->year + 1;
            $year++
        ) {
            for ($month = 1; $month <= 12; $month++) {
                Cache::forget(
                    sprintf(
                        '%s:%d:%d',
                        self::STATS_CACHE_KEY,
                        $year,
                        $month
                    )
                );
            }
        }
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
     * Generic server error response.
     */
    private function serverError(
        string $message
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 500);
    }
}

