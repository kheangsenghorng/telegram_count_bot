<?php

namespace App\Http\Controllers\Api\Customer\PackageTransaction;

use App\Http\Controllers\Controller;
use App\Models\PackageTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PackageTransactionController extends Controller
{
    /**
     * GET /api/customer/transactions
     * Authenticated customer's own transactions, newest first.
     *   ?status=pending|paid|expired
     *   ?per_page=10
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $userUuid = $request->user()->uuid;

            $query = PackageTransaction::query()
                ->with('package:packagesID,name,price')
                ->where('user_id', $userUuid)
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->orderByDesc('created_at');

            $perPage = min((int) $request->input('per_page', 10), 50);
            $transactions = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data'    => collect($transactions->items())
                    ->map(fn ($t) => $this->customerPayload($t)),
                'meta'    => [
                    'current_page' => $transactions->currentPage(),
                    'last_page'    => $transactions->lastPage(),
                    'per_page'     => $transactions->perPage(),
                    'total'        => $transactions->total(),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Customer transaction index failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch your transactions.',
            ], 500);
        }
    }

    /**
     * GET /api/customer/transactions/{id}
     * Own transaction only — returns 404 (not 403) for other users'
     * transactions so IDs can't be probed.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $transaction = PackageTransaction::query()
                ->with('package:packagesID,name,price')
                ->where('packageTransactionsID', $id)
                ->where('user_id', $request->user()->uuid)
                ->first();

            if (! $transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found.',
                ], 404);
            }

            $this->expireIfPastDue($transaction);

            return response()->json([
                'success' => true,
                'data'    => $this->customerPayload($transaction),
            ]);
        } catch (Throwable $e) {
            Log::error('Customer transaction show failed', [
                'id'    => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch transaction.',
            ], 500);
        }
    }

    /**
     * Same guarded lazy-expire as the public controller.
     */
    private function expireIfPastDue(PackageTransaction $transaction): void
    {
        if (
            $transaction->status === 'pending'
            && $transaction->expires_at
            && $transaction->expires_at->isPast()
        ) {
            PackageTransaction::query()
                ->where('packageTransactionsID', $transaction->packageTransactionsID)
                ->where('status', 'pending')
                ->update(['status' => 'expired']);

            $transaction->status = 'expired';
        }
    }

    /**
     * Customer-safe payload — no md5, no gateway internals.
     */
    private function customerPayload(PackageTransaction $transaction): array
    {
        return [
            'packageTransactionsID' => $transaction->packageTransactionsID,
            'package'               => $transaction->package
                ? [
                    'packagesID' => $transaction->package->packagesID,
                    'name'       => $transaction->package->name,
                    'price'      => $transaction->package->price,
                ]
                : null,
            'amount'     => $transaction->amount,
            'currency'   => $transaction->currency,
            'gateway'    => $transaction->gateway,
            'status'     => $transaction->status,
            'expires_at' => $transaction->expires_at?->toIso8601String(),
            'paid_at'    => $transaction->paid_at?->toIso8601String(),
            'created_at' => $transaction->created_at?->toIso8601String(),
        ];
    }
}