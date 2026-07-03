<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PackageTransaction;
use App\Services\Khqr\BakongService as KhqrBakongService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class KhqrController extends Controller
{
    public function __construct(
        protected KhqrBakongService $bakong
    ) {}

    public function generateMerchant(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'bakong_account_id' => [
                'required',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9._-]+@[A-Za-z0-9._-]+$/',
            ],
            'merchant_id' => ['required', 'string', 'max:32'],
            'acquiring_bank' => ['required', 'string', 'max:32'],
            'merchant_name' => ['required', 'string', 'max:25'],

            'currency' => ['nullable', 'string', 'in:khr,usd'],
            'amount' => ['nullable', 'numeric', 'min:0'],

            'merchant_city' => ['nullable', 'string', 'max:15'],
            'bill_number' => ['nullable', 'string', 'max:25'],
            'mobile_number' => ['nullable', 'digits_between:1,25'],
            'store_label' => ['nullable', 'string', 'max:25'],
            'terminal_label' => ['nullable', 'string', 'max:25'],
            'purpose_of_transaction' => ['nullable', 'string', 'max:25'],
            'upi_account_information' => ['nullable', 'string', 'max:31'],
            'expiration_timestamp' => ['nullable', 'integer', 'min:1'],
            'merchant_category_code' => ['nullable', 'regex:/^\d{4}$/'],
        ]);

        if ($request->filled('amount') && ! $request->filled('expiration_timestamp')) {
            $validator->after(function ($validator) {
                $validator->errors()->add(
                    'expiration_timestamp',
                    'Expiration timestamp is required for dynamic KHQR'
                );
            });
        }

        if ($validator->fails()) {
            return response()->json([
                'status' => [
                    'code' => 1,
                    'errorCode' => 8,
                    'message' => $validator->errors()->first(),
                ],
                'data' => null,
            ], 422);
        }

        $payload = $validator->validated();

        $payload['currency'] = strtolower($payload['currency'] ?? 'khr');
        $payload['merchant_city'] = $payload['merchant_city'] ?? 'Phnom Penh';
        $payload['merchant_category_code'] = $payload['merchant_category_code'] ?? '5999';

        $result = $this->bakong->generateMerchantKhqr($payload);

        if (! $result['success']) {
            return response()->json([
                'status' => [
                    'code' => 1,
                    'errorCode' => $result['status'] === 422 ? 8 : 15,
                    'message' => $result['message'] ?? 'Internal Server Error',
                ],
                'data' => null,
            ], $result['status'] ?? 500);
        }

        return response()->json($result['data'], 200);
    }

    public function generateIndividual(Request $request): JsonResponse
    {
        $result = $this->bakong->generateIndividualKhqr($request->all());

        if (! $result['success']) {
            return response()->json($result, $result['status'] ?? 500);
        }

        return response()->json($result['data'], 200);
    }

    public function generateImage(Request $request): JsonResponse
    {
        $request->validate([
            'qr' => ['required', 'string'],
        ]);

        $result = $this->bakong->generateKhqrImage($request->all());

        if (! $result['success']) {
            return response()->json($result, $result['status'] ?? 500);
        }

        return response()->json($result['data'], 200);
    }

    public function generateDeeplink(Request $request): JsonResponse
    {
        $request->validate([
            'qr_code' => ['required', 'string'],
            'app_icon_url' => ['nullable', 'string'],
            'app_name' => ['nullable', 'string'],
            'app_deep_link_callback' => ['nullable', 'string'],
        ]);

        $result = $this->bakong->generateDeeplink($request->all());

        if (! $result['success']) {
            return response()->json($result, $result['status'] ?? 500);
        }

        return response()->json($result['data'], 200);
    }

    public function checkTransactionByMd5(Request $request): JsonResponse
    {
        $request->validate([
            'md5' => ['required', 'string'],
        ]);

        $result = $this->bakong->checkTransactionByMd5($request->md5);

        if (! $result['success']) {
            return response()->json($result, $result['status'] ?? 500);
        }

        return response()->json($result['data'], 200);
    }

    public function checkTransactionByHash(Request $request): JsonResponse
    {
        $request->validate([
            'hash' => ['required', 'string'],
        ]);

        $result = $this->bakong->checkTransactionByHash($request->hash);

        if (! $result['success']) {
            return response()->json($result, $result['status'] ?? 500);
        }

        return response()->json($result['data'], 200);
    }

    public function checkBakongAccount(Request $request): JsonResponse
    {
        $request->validate([
            'bakong_account_id' => ['required', 'string'],
        ]);

        $result = $this->bakong->checkBakongAccount($request->bakong_account_id);

        if (! $result['success']) {
            return response()->json($result, $result['status'] ?? 500);
        }

        return response()->json($result['data'], 200);
    }

    public function checkTransactionByExternalRef(Request $request): JsonResponse
    {
        $request->validate([
            'external_ref' => ['required', 'string'],
        ]);

        $result = $this->bakong->checkTransactionByExternalRef($request->external_ref);

        if (! $result['success']) {
            return response()->json($result, $result['status'] ?? 500);
        }

        return response()->json($result['data'], 200);
    }
    public function checkoutUrl(PackageTransaction $transaction): string
{
    return rtrim((string) config('services.frontend.url'), '/') .
        '/pay/' . $transaction->packageTransactionsID;
}
}