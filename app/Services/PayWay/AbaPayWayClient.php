<?php

declare(strict_types=1);

namespace App\Services\PayWay;

use CURLFile;
use CurlHandle;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class AbaPayWayClient
{
    public function __construct(
        private readonly string $merchantId,
        private readonly string $apiKey,
        private readonly string $rsaPublicKey,
        private readonly string $baseUrl,
        private readonly int $connectTimeout = 10,
        private readonly int $timeout = 30,
    ) {
        if (trim($this->merchantId) === '') {
            throw new InvalidArgumentException(
                'PayWay merchant ID is required.'
            );
        }

        if (trim($this->apiKey) === '') {
            throw new InvalidArgumentException(
                'PayWay API key is required.'
            );
        }

        if (trim($this->rsaPublicKey) === '') {
            throw new InvalidArgumentException(
                'PayWay RSA public key is required.'
            );
        }

        if (trim($this->baseUrl) === '') {
            throw new InvalidArgumentException(
                'PayWay base URL is required.'
            );
        }

        if ($this->connectTimeout < 1) {
            throw new InvalidArgumentException(
                'Connect timeout must be at least one second.'
            );
        }

        if ($this->timeout < 1) {
            throw new InvalidArgumentException(
                'Request timeout must be at least one second.'
            );
        }
    }

    /**
     * Create an ABA PayWay payment link.
     *
     * Supported keys:
     *
     * title           string
     * amount          numeric
     * currency        USD|KHR
     * description     string|null
     * payment_limit   int|null
     * expired_date    int|null  (epoch MILLISECONDS, 13 digits)
     * return_url      string
     * merchant_ref_no string|null
     * payout          array|null
     *
     * @throws JsonException
     */
    public function createPaymentLink(
        array $link,
        ?string $imagePath = null
    ): array {
        $this->validatePaymentLink($link);

        // ── Required fields only. Optional fields are appended below
        //    ONLY when present — PayWay's payment-link validator rejects
        //    null values in merchant_auth (PTL04).
        $payload = [
            'mc_id' => $this->merchantId,

            // ABA limits the payment-link title; Khmer characters count.
            'title' => mb_substr(
                (string) $link['title'],
                0,
                100
            ),

            'amount' => (string) $link['amount'],

            'currency' => strtoupper(
                (string) $link['currency']
            ),

            'return_url' => base64_encode(
                (string) $link['return_url']
            ),
        ];
        if (! empty($link['expired_date'])) {
            $expiredDate = (int) $link['expired_date'];
        
            // ABA payment-link API expects a Unix timestamp in SECONDS
            // (10 digits). Convert if milliseconds (13 digits) slipped in.
            if ($expiredDate > 100_000_000_000) {
                $expiredDate = intdiv($expiredDate, 1000);
            }
        
            if ($expiredDate <= time()) {
                throw new InvalidArgumentException(
                    'The payment-link expiry must be in the future.'
                );
            }
        
            $payload['expired_date'] = (string) $expiredDate;
        }
        
        if (
            isset($link['description'])
            && $link['description'] !== ''
        ) {
            $payload['description'] = mb_substr(
                (string) $link['description'],
                0,
                255
            );
        }

        if (
            isset($link['payment_limit'])
            && $link['payment_limit'] !== null
        ) {
            $payload['payment_limit'] =
                (string) $link['payment_limit'];
        }

        if (
            isset($link['merchant_ref_no'])
            && $link['merchant_ref_no'] !== ''
        ) {
            $payload['merchant_ref_no'] =
                (string) $link['merchant_ref_no'];
        }

        if (! empty($link['payout'])) {
            $payload['payout'] = json_encode(
                $link['payout'],
                JSON_THROW_ON_ERROR
            );
        }

        // Safe to log: this is the pre-encryption merchant_auth body.
        // It contains no API key and no RSA material.
        Log::debug('PayWay payment-link payload', $payload);

        $requestTime = gmdate('YmdHis');

        $merchantAuth = $this->encryptMerchantAuth(
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            )
        );

        $hash = $this->generateHash(
            requestTime: $requestTime,
            merchantAuth: $merchantAuth
        );

        $form = [
            'request_time' => $requestTime,
            'merchant_id' => $this->merchantId,
            'merchant_auth' => $merchantAuth,
            'hash' => $hash,
        ];

        if ($imagePath !== null) {
            $this->validateImage($imagePath);

            $form['image'] = new CURLFile(
                $imagePath,
                mime_content_type($imagePath) ?: null,
                basename($imagePath)
            );
        }

        return $this->postMultipart(
            '/api/merchant-portal/merchant-access/payment-link/create',
            $form
        );
    }

    /**
     * Verify a completed PayWay transaction.
     *
     * Use the transaction ID received in the PayWay callback.
     */
    public function checkTransaction(
        string $transactionId
    ): array {
        $transactionId = trim($transactionId);

        if ($transactionId === '') {
            throw new InvalidArgumentException(
                'Transaction ID is required.'
            );
        }

        $requestTime = gmdate('YmdHis');

        $beforeHash = $requestTime
            .$this->merchantId
            .$transactionId;

        $hash = base64_encode(
            hash_hmac(
                'sha512',
                $beforeHash,
                $this->apiKey,
                true
            )
        );

        return $this->postJson(
            '/api/payment-gateway/v1/payments/check-transaction-2',
            [
                'req_time' => $requestTime,
                'merchant_id' => $this->merchantId,
                'tran_id' => $transactionId,
                'hash' => $hash,
            ]
        );
    }

    /**
     * Retrieve payment-link details.
     *
     * @throws JsonException
     */
    public function getPaymentLinkDetails(
        string $paymentLinkId
    ): array {
        $paymentLinkId = trim($paymentLinkId);

        if ($paymentLinkId === '') {
            throw new InvalidArgumentException(
                'Payment-link ID is required.'
            );
        }

        $requestTime = gmdate('YmdHis');

        $merchantPayload = json_encode([
            'mc_id' => $this->merchantId,
            'id' => $paymentLinkId,
        ], JSON_THROW_ON_ERROR);

        $merchantAuth = $this->encryptMerchantAuth(
            $merchantPayload
        );

        $hash = $this->generateHash(
            requestTime: $requestTime,
            merchantAuth: $merchantAuth
        );

        return $this->postJson(
            '/api/merchant-portal/merchant-access/payment-link/detail',
            [
                'request_time' => $requestTime,
                'merchant_id' => $this->merchantId,
                'merchant_auth' => $merchantAuth,
                'hash' => $hash,
            ]
        );
    }

    private function validatePaymentLink(array $link): void
    {
        foreach (
            ['title', 'amount', 'currency', 'return_url']
            as $field
        ) {
            if (
                ! array_key_exists($field, $link)
                || $link[$field] === null
                || $link[$field] === ''
            ) {
                throw new InvalidArgumentException(
                    "Missing required field: {$field}"
                );
            }
        }

        $currency = strtoupper(
            (string) $link['currency']
        );

        if (! in_array($currency, ['USD', 'KHR'], true)) {
            throw new InvalidArgumentException(
                'Currency must be USD or KHR.'
            );
        }

        if (! is_numeric($link['amount'])) {
            throw new InvalidArgumentException(
                'Payment amount must be numeric.'
            );
        }

        $amount = (float) $link['amount'];
        $minimumAmount = $currency === 'KHR'
            ? 100
            : 0.01;

        if ($amount < $minimumAmount) {
            throw new InvalidArgumentException(
                "The minimum payment amount is "
                ."{$minimumAmount} {$currency}."
            );
        }

        $returnUrl = (string) $link['return_url'];

        if (
            filter_var(
                $returnUrl,
                FILTER_VALIDATE_URL
            ) === false
        ) {
            throw new InvalidArgumentException(
                'The PayWay return URL is invalid.'
            );
        }

        $scheme = strtolower(
            (string) parse_url(
                $returnUrl,
                PHP_URL_SCHEME
            )
        );

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException(
                'The return URL must use HTTP or HTTPS.'
            );
        }
    }

    /**
     * Encrypt merchant_auth using the ABA RSA public key.
     *
     * PKCS#1 v1.5 padding requires 11 bytes, so the maximum chunk
     * length depends on the RSA key size.
     */
    private function encryptMerchantAuth(
        string $plainText
    ): string {
        $publicKey = openssl_pkey_get_public(
            $this->rsaPublicKey
        );

        if ($publicKey === false) {
            throw new RuntimeException(
                'Invalid PayWay RSA public key: '
                .$this->getOpenSslError()
            );
        }

        $keyDetails = openssl_pkey_get_details(
            $publicKey
        );

        if (
            ! is_array($keyDetails)
            || ! isset($keyDetails['bits'])
        ) {
            throw new RuntimeException(
                'Unable to read PayWay RSA key details.'
            );
        }

        $keyBytes = intdiv(
            (int) $keyDetails['bits'],
            8
        );

        $maximumChunkLength = $keyBytes - 11;

        if ($maximumChunkLength < 1) {
            throw new RuntimeException(
                'Invalid PayWay RSA key size.'
            );
        }

        $encryptedOutput = '';
        $offset = 0;
        $plainTextLength = strlen($plainText);

        while ($offset < $plainTextLength) {
            $chunk = substr(
                $plainText,
                $offset,
                $maximumChunkLength
            );

            $encryptedChunk = '';

            $encrypted = openssl_public_encrypt(
                $chunk,
                $encryptedChunk,
                $publicKey,
                OPENSSL_PKCS1_PADDING
            );

            if (! $encrypted) {
                throw new RuntimeException(
                    'PayWay RSA encryption failed: '
                    .$this->getOpenSslError()
                );
            }

            $encryptedOutput .= $encryptedChunk;
            $offset += $maximumChunkLength;
        }

        return base64_encode($encryptedOutput);
    }

    /**
     * Generate Base64 HMAC-SHA512 of:
     *
     * request_time + merchant_id + merchant_auth
     */
    private function generateHash(
        string $requestTime,
        string $merchantAuth
    ): string {
        $beforeHash = $requestTime
            .$this->merchantId
            .$merchantAuth;

        return base64_encode(
            hash_hmac(
                'sha512',
                $beforeHash,
                $this->apiKey,
                true
            )
        );
    }

    private function validateImage(string $path): void
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException(
                "Payment-link image not found: {$path}"
            );
        }

        $fileSize = filesize($path);

        if (
            $fileSize === false
            || $fileSize > 3 * 1024 * 1024
        ) {
            throw new InvalidArgumentException(
                'Payment-link image must not exceed 3 MB.'
            );
        }

        $extension = strtolower(
            pathinfo($path, PATHINFO_EXTENSION)
        );

        if (
            ! in_array(
                $extension,
                ['jpg', 'jpeg', 'png'],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Payment-link image must be JPG, JPEG, or PNG.'
            );
        }
    }

    private function postMultipart(
        string $path,
        array $form
    ): array {
        $curl = $this->createCurlHandle($path);

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $form,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]);

        return $this->executeRequest($curl);
    }

    /**
     * @throws JsonException
     */
    private function postJson(
        string $path,
        array $payload
    ): array {
        $curl = $this->createCurlHandle($path);

        curl_setopt_array($curl, [
            CURLOPT_POST => true,

            CURLOPT_POSTFIELDS => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),

            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);

        return $this->executeRequest($curl);
    }

    private function createCurlHandle(
        string $path
    ): CurlHandle {
        $url = rtrim($this->baseUrl, '/')
            .'/'
            .ltrim($path, '/');

        $curl = curl_init($url);

        if (! $curl instanceof CurlHandle) {
            throw new RuntimeException(
                'Unable to initialize the PayWay request.'
            );
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        return $curl;
    }

    private function executeRequest(
        CurlHandle $curl
    ): array {
        $body = curl_exec($curl);

        if ($body === false) {
            $error = curl_error($curl);
            $errorNumber = curl_errno($curl);

            curl_close($curl);

            throw new RuntimeException(
                "PayWay connection error "
                ."({$errorNumber}): {$error}"
            );
        }

        $httpCode = (int) curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );

        curl_close($curl);

        try {
            $response = json_decode(
                $body,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Invalid PayWay JSON response "
                ."(HTTP {$httpCode}): {$body}",
                previous: $exception
            );
        }

        if (! is_array($response)) {
            throw new RuntimeException(
                "Unexpected PayWay response "
                ."(HTTP {$httpCode})."
            );
        }

        $statusCode = isset(
            $response['status']['code']
        )
            ? (string) $response['status']['code']
            : null;

        if (
            $httpCode < 200
            || $httpCode >= 300
            || (
                $statusCode !== null
                && $statusCode !== '00'
            )
        ) {
            $message = (string) (
                $response['status']['message']
                ?? 'Unknown PayWay error'
            );

            // Full response body helps diagnose which parameter failed
            // (PTL04 responses often include a "data" or "errors" node).
            Log::warning('PayWay error response', [
                'http_code' => $httpCode,
                'response' => $response,
            ]);

            throw new RuntimeException(
                "PayWay error "
                ."{$statusCode} "
                ."(HTTP {$httpCode}): {$message}"
            );
        }

        return $response;
    }

    private function getOpenSslError(): string
    {
        $errors = [];

        while (
            ($error = openssl_error_string()) !== false
        ) {
            $errors[] = $error;
        }

        return $errors !== []
            ? implode(' | ', $errors)
            : 'Unknown OpenSSL error';
    }
}