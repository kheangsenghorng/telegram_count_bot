<?php

namespace App\Services\Khqr;

use App\Models\Package;
use App\Models\PackageTransaction;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;
use Illuminate\Support\Str;
class BakongService
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected ?string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.bakong_gateway.url'), '/');
        $this->apiKey = config('services.bakong_gateway.api_key');
        $this->token = config('services.bakong_gateway.token');
    }

    protected function request(string $method, string $endpoint, array $data = []): array
    {
        try {
            if (empty($this->baseUrl)) {
                throw new \Exception('Bakong gateway URL is not configured.');
            }

            $headers = [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ];

            if (! empty($this->apiKey)) {
                $headers['X-API-KEY'] = $this->apiKey;
            }

            $http = Http::withHeaders($headers)
                ->timeout(30);

            if (! empty($this->token)) {
                $http = $http->withToken($this->token);
            }

            $url = $this->baseUrl . $endpoint;

            $response = match (strtolower($method)) {
                'get' => $http->get($url, $data),
                'post' => $http->post($url, $data),
                'put' => $http->put($url, $data),
                'patch' => $http->patch($url, $data),
                'delete' => $http->delete($url, $data),
                default => throw new \Exception("Unsupported HTTP method: {$method}"),
            };

            $json = $response->json();

            if ($response->successful()) {
                return [
                    'success' => true,
                    'status' => $response->status(),
                    'data' => $json,
                ];
            }

            return [
                'success' => false,
                'status' => $response->status(),
                'message' => data_get($json, 'status.message')
                    ?? data_get($json, 'message')
                    ?? 'Request failed',
                'errors' => $json,
            ];
        } catch (RequestException $e) {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'HTTP Request Exception',
                'error' => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'Unexpected Exception',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function generateIndividualKhqr(array $payload): array
    {
        return $this->request('post', '/api/v1/khqr/individual', [
            'bakong_account_id' => $payload['bakong_account_id'] ?? null,
            'merchant_name' => $payload['merchant_name'] ?? null,
            'account_information' => $payload['account_information'] ?? null,
            'acquiring_bank' => $payload['acquiring_bank'] ?? null,
            'currency' => strtolower($payload['currency'] ?? 'khr'),
            'amount' => $payload['amount'] ?? null,
            'merchant_city' => $payload['merchant_city'] ?? 'Phnom Penh',
            'bill_number' => $payload['bill_number'] ?? null,
            'mobile_number' => $payload['mobile_number'] ?? null,
            'store_label' => $payload['store_label'] ?? null,
            'terminal_label' => $payload['terminal_label'] ?? null,
            'purpose_of_transaction' => $payload['purpose_of_transaction'] ?? 'Payment',
            'expiration_timestamp' => $payload['expiration_timestamp'] ?? null,
            'merchant_category_code' => $payload['merchant_category_code'] ?? '5999',
        ]);
    }

    public function generateMerchantKhqr(array $payload): array
    {
        return $this->request('post', '/api/v1/khqr/merchant', [
            'bakong_account_id' => $payload['bakong_account_id'] ?? null,
            'merchant_id' => $payload['merchant_id'] ?? null,
            'acquiring_bank' => $payload['acquiring_bank'] ?? null,
            'currency' => strtolower($payload['currency'] ?? 'khr'),
            'amount' => $payload['amount'] ?? null,
            'merchant_name' => $payload['merchant_name'] ?? null,
            'merchant_city' => $payload['merchant_city'] ?? 'Phnom Penh',
            'bill_number' => $payload['bill_number'] ?? null,
            'mobile_number' => $payload['mobile_number'] ?? null,
            'store_label' => $payload['store_label'] ?? null,
            'terminal_label' => $payload['terminal_label'] ?? null,
            'purpose_of_transaction' => $payload['purpose_of_transaction'] ?? null,
            'upi_account_information' => $payload['upi_account_information'] ?? null,
            'expiration_timestamp' => $payload['expiration_timestamp'] ?? null,
            'merchant_category_code' => $payload['merchant_category_code'] ?? '5999',
        ]);
    }

    public function generateKhqrImage(array $payload): array
    {
        return $this->request('post', '/api/v1/khqr/generate-image', [
            'qr' => $payload['qr'] ?? null,
        ]);
    }

    public function generateDeeplink(array $payload): array
    {
        return $this->request('post', '/api/v1/khqr/deeplink', [
            'qr_code' => $payload['qr_code'] ?? null,
            'app_icon_url' => $payload['app_icon_url'] ?? null,
            'app_name' => $payload['app_name'] ?? null,
            'app_deep_link_callback' => $payload['app_deep_link_callback'] ?? null,
        ]);
    }

    public function checkTransactionByMd5(string $md5): array
    {
        return $this->request('post', '/api/v1/khqr/check-transaction-by-md5', [
            'md5' => $md5,
        ]);
    }

    public function checkTransactionByHash(string $hash): array
    {
        return $this->request('post', '/api/v1/khqr/check-transaction-by-hash', [
            'hash' => $hash,
        ]);
    }

    public function checkBakongAccount(string $bakongAccountId): array
    {
        return $this->request('post', '/api/v1/khqr/check-bakong-account', [
            'bakong_account_id' => $bakongAccountId,
        ]);
    }

    public function checkTransactionByExternalRef(string $externalRef): array
    {
        return $this->request('post', '/api/v1/khqr/check-transaction-by-external-ref', [
            'external_ref' => $externalRef,
        ]);
    }
    public function createCheckout(
        Package $package,
        int $telegramUserId,
        string $requestedBy
    ): PackageTransaction {
        $bakongAccountId = config('services.bakong.account_id');
    
        if (empty($bakongAccountId)) {
            throw new \Exception('BAKONG_ACCOUNT_ID is missing in .env');
        }
    
        $user = User::where('telegram_id', $telegramUserId)->first();
    
        if (! $user) {
            throw new \Exception("User not found with telegram_id: {$telegramUserId}");
        }
    
        $externalRef = 'PKG-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));
    
        $amount = (float) $package->price;
        $currency = 'USD';
        $expiresAt = now()->addMinutes(15);
    
        $khqr = $this->generateIndividualKhqr([
            'bakong_account_id' => $bakongAccountId,
            'merchant_name' => config('services.bakong.merchant_name', 'CHEN KHEANG'),
            'account_information' => null,
            'acquiring_bank' => config('services.bakong.acquiring_bank'),
            'currency' => strtolower($currency),
            'amount' => $amount,
            'merchant_city' => config('services.bakong.merchant_city', 'Phnom Penh'),
            'bill_number' => $externalRef,
            'mobile_number' => null,
            'store_label' => 'Telegram Count',
            'terminal_label' => 'Telegram Bot',
            'purpose_of_transaction' => 'Package Payment',
            'expiration_timestamp' => $expiresAt->getTimestamp() * 1000,
            'merchant_category_code' => '5999',
        ]);
    
        if (! data_get($khqr, 'success')) {
            throw new \Exception(
                data_get($khqr, 'message')
                ?? data_get($khqr, 'errors.responseMessage')
                ?? data_get($khqr, 'errors.status.message')
                ?? 'Generate KHQR failed'
            );
        }
    
        /*
        |--------------------------------------------------------------------------
        | Get raw KHQR string
        |--------------------------------------------------------------------------
        */
        $qrCode = data_get($khqr, 'data.data.qr')
            ?? data_get($khqr, 'data.qr')
            ?? data_get($khqr, 'data.data.khqr')
            ?? data_get($khqr, 'data.khqr')
            ?? data_get($khqr, 'qr')
            ?? data_get($khqr, 'khqr');
    
        if (empty($qrCode)) {
            \Log::error('KHQR generated but QR code not found', [
                'khqr_response' => $khqr,
            ]);
    
            throw new \Exception('KHQR generated but QR code not found in response.');
        }
    
        /*
        |--------------------------------------------------------------------------
        | Generate QR image and get MD5
        |--------------------------------------------------------------------------
        | Your generate-image response:
        | data.image = QR image base64 SVG
        | data.md5   = MD5 for checking transaction
        */
        $qrImage = $this->generateKhqrImage([
            'qr' => $qrCode,
        ]);
    
        if (! data_get($qrImage, 'success')) {
            \Log::error('Generate QR image failed', [
                'qr_image_response' => $qrImage,
            ]);
    
            throw new \Exception(
                data_get($qrImage, 'message')
                ?? data_get($qrImage, 'errors.message')
                ?? 'Generate QR image failed'
            );
        }
    
        $qrImageUrl = data_get($qrImage, 'data.data.image')
            ?? data_get($qrImage, 'data.image');
    
        $md5 = data_get($qrImage, 'data.data.md5')
            ?? data_get($qrImage, 'data.md5');
    
        if (empty($qrImageUrl)) {
            \Log::error('QR image not found in generate-image response', [
                'qr_image_response' => $qrImage,
            ]);
    
            throw new \Exception('QR image not found in generate-image response.');
        }
    
        if (empty($md5)) {
            \Log::error('MD5 not found in generate-image response', [
                'qr_image_response' => $qrImage,
            ]);
    
            throw new \Exception('MD5 not found in generate-image response.');
        }
    
        return PackageTransaction::create([
            'user_id' => $user->uuid,
            'subscription_id' => null,
            'package_id' => $package->packagesID,
    
            'amount' => $amount,
            'currency' => $currency,
            'payment_method' => 'bakong_khqr',
            'external_transaction_id' => $externalRef,
    
            'qr_code' => $qrCode,
            'qr_image_url' => $qrImageUrl,
            'md5' => $md5,
            'expires_at' => $expiresAt,
    
            'status' => 'pending',
            'paid_at' => null,
        ]);
    }
    public function checkoutUrl(PackageTransaction $transaction): string
    {
        return rtrim((string) config('services.frontend.url'), '/') .
            '/pay/' . $transaction->packageTransactionsID;
    }
}