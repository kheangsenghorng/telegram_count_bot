<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ABA PayWay Checkout integration.
 *
 * Supports two flows:
 *  1. Hosted checkout  (payment_option = abapay_khqr)          -> browser form-POST redirect
 *  2. Deeplink / QR    (payment_option = abapay_khqr_deeplink) -> JSON { qr_string, abapay_deeplink, checkout_qr_url }
 *
 * IMPORTANT (hash): the value used inside the hash MUST be byte-identical to the
 * value sent in the form. All base64 encoding therefore happens BEFORE hashing.
 */
class PayWayService
{
    public const OPTION_HOSTED   = 'abapay_khqr';
    public const OPTION_DEEPLINK = 'abapay_khqr_deeplink';

    private const PURCHASE_PATH = '/api/payment-gateway/v1/payments/purchase';

    /**
     * NOTE: confirm this path + hash fields against the "Get a transaction details"
     * page of your PayWay doc before production. Field order here follows the
     * common PayWay pattern: req_time + merchant_id + tran_id.
     */
    private const CHECK_PATH = '/api/payment-gateway/v1/payments/check-transaction-2';

    /**
     * Generate a PayWay-safe tran_id (max 20 chars).
     * Format: TC + yymmddHHiiss + 6 random = 20 chars, sortable by time.
     */
    public function generateTranId(): string
    {
        return 'TC' . now('Asia/Phnom_Penh')->format('ymdHis') . strtoupper(Str::random(6));
    }

    /**
     * Build the full multipart body (including hash) for a purchase request.
     *
     * @param string      $tranId        <= 20 chars, unique per payment attempt
     * @param float       $amount
     * @param string      $currency      KHR | USD
     * @param string      $paymentOption self::OPTION_HOSTED or self::OPTION_DEEPLINK
     * @param array|null  $items         [['name' => ..., 'quantity' => ..., 'price' => ...], ...]
     * @param string|null $returnParams  echoed back on the callback (e.g. your package_transaction key)
     */
    public function buildPurchaseParams(
        string $tranId,
        float $amount,
        string $currency,
        string $paymentOption,
        ?array $items = null,
        ?string $returnParams = null,
    ): array {
        $cfg = config('payway');

        if (empty($cfg['merchant_id']) || empty($cfg['api_key'])) {
            throw new RuntimeException('PayWay merchant_id / api_key is not configured.');
        }

        $params = [
            'req_time'             => now('UTC')->format('YmdHis'),
            'merchant_id'          => $cfg['merchant_id'],
            'tran_id'              => $tranId,
            'firstname'            => '',
            'lastname'             => '',
            'email'                => '',
            'phone'                => '',
            'type'                 => 'purchase',
            'payment_option'       => $paymentOption,
            'items'                => $items ? base64_encode(json_encode($items)) : '',
            'shipping'             => '',
            'amount'               => number_format($amount, 2, '.', ''),
            'currency'             => strtoupper($currency),
            'return_url'           => base64_encode($cfg['callback_url']),
            'cancel_url'           => $cfg['cancel_url'] ?? '',
            'skip_success_page'    => '',
            'continue_success_url' => $cfg['continue_success_url'] ?? '',
            'return_deeplink'      => '',
            'custom_fields'        => '',
            'return_params'        => $returnParams ?? '',
            'view_type'            => 'hosted_view',
            'payout'               => '',
            'additional_params'    => '',
            'lifetime'             => (string) $cfg['lifetime'],
            'google_pay_token'     => '',
        ];

        $params['hash'] = $this->makePurchaseHash($params);

        return $params;
    }

    /**
     * Hosted checkout: return the endpoint + params so the frontend can
     * auto-submit a form POST (browser redirect to PayWay's checkout page).
     */
    public function hostedCheckout(
        string $tranId,
        float $amount,
        string $currency,
        ?array $items = null,
        ?string $returnParams = null,
    ): array {
        return [
            'action_url' => rtrim(config('payway.base_url'), '/') . self::PURCHASE_PATH,
            'method'     => 'POST',
            'params'     => $this->buildPurchaseParams($tranId, $amount, $currency, self::OPTION_HOSTED, $items, $returnParams),
        ];
    }

    /**
     * Deeplink / QR checkout: server-to-server call. PayWay responds with JSON:
     * { qr_string, abapay_deeplink, checkout_qr_url, ... }
     *
     * Store the returned values on the transaction row immediately —
     * never regenerate on poll (links differ per API call).
     */
    public function deeplinkCheckout(
        string $tranId,
        float $amount,
        string $currency,
        ?array $items = null,
        ?string $returnParams = null,
    ): array {
        $params = $this->buildPurchaseParams($tranId, $amount, $currency, self::OPTION_DEEPLINK, $items, $returnParams);

        $response = $this->multipart($params)
            ->post(rtrim(config('payway.base_url'), '/') . self::PURCHASE_PATH);

        if ($response->failed()) {
            Log::error('PayWay deeplink purchase failed', [
                'tran_id' => $tranId,
                'status'  => $response->status(),
                'body'    => Str::limit($response->body(), 500),
            ]);
            throw new RuntimeException('PayWay purchase request failed (HTTP ' . $response->status() . ').');
        }

        $data = $response->json();

        if (!is_array($data) || empty($data['qr_string'])) {
            Log::error('PayWay deeplink purchase: unexpected response shape', [
                'tran_id' => $tranId,
                'body'    => Str::limit($response->body(), 500),
            ]);
            throw new RuntimeException('PayWay returned an unexpected response.');
        }

        return [
            'qr_string'       => $data['qr_string'],
            'abapay_deeplink' => $data['abapay_deeplink'] ?? null,
            'checkout_qr_url' => $data['checkout_qr_url'] ?? null,
            'raw'             => $data,
        ];
    }

    /**
     * Server-side verification of a transaction's real status.
     * ALWAYS call this before activating a package — never trust the
     * callback body alone.
     */
    public function checkTransaction(string $tranId): array
    {
        $cfg = config('payway');

        $reqTime = now('UTC')->format('YmdHis');
        $hash    = base64_encode(hash_hmac(
            'sha512',
            $reqTime . $cfg['merchant_id'] . $tranId,
            $cfg['api_key'],
            true
        ));

        $response = $this->multipart([
            'req_time'    => $reqTime,
            'merchant_id' => $cfg['merchant_id'],
            'tran_id'     => $tranId,
            'hash'        => $hash,
        ])->post(rtrim($cfg['base_url'], '/') . self::CHECK_PATH);

        if ($response->failed()) {
            Log::warning('PayWay check-transaction failed', [
                'tran_id' => $tranId,
                'status'  => $response->status(),
            ]);
            return ['ok' => false, 'raw' => null];
        }

        $data = $response->json() ?? [];

        // PayWay conventions: status.code === "00" and payment_status APPROVED
        // mean success. Adjust once you confirm the response shape in the doc.
        $code          = data_get($data, 'status.code');
        $paymentStatus = strtoupper((string) data_get($data, 'data.payment_status', ''));

        return [
            'ok'   => $code === '00' && $paymentStatus === 'APPROVED',
            'raw'  => $data,
        ];
    }

    // ------------------------------------------------------------------

    /**
     * Purchase hash — field order is defined by ABA and must not change:
     * req_time . merchant_id . tran_id . amount . items . shipping .
     * firstname . lastname . email . phone . type . payment_option .
     * return_url . cancel_url . continue_success_url . return_deeplink .
     * currency . custom_fields . return_params . payout . lifetime .
     * additional_params . google_pay_token . skip_success_page
     */
    private function makePurchaseHash(array $p): string
    {
        $b4hash =
            $p['req_time'] .
            $p['merchant_id'] .
            $p['tran_id'] .
            $p['amount'] .
            $p['items'] .
            $p['shipping'] .
            $p['firstname'] .
            $p['lastname'] .
            $p['email'] .
            $p['phone'] .
            $p['type'] .
            $p['payment_option'] .
            $p['return_url'] .
            $p['cancel_url'] .
            $p['continue_success_url'] .
            $p['return_deeplink'] .
            $p['currency'] .
            $p['custom_fields'] .
            $p['return_params'] .
            $p['payout'] .
            $p['lifetime'] .
            $p['additional_params'] .
            $p['google_pay_token'] .
            $p['skip_success_page'];

        return base64_encode(hash_hmac('sha512', $b4hash, config('payway.api_key'), true));
    }

    /**
     * PayWay requires multipart/form-data. Laravel's Http client switches to
     * multipart when attach() is used; asMultipart() with key/value pairs.
     */
    private function multipart(array $fields)
    {
        $request = Http::asMultipart()->timeout(30);

        foreach ($fields as $name => $value) {
            $request = $request->attach($name, (string) $value);
        }

        return $request;
    }
}