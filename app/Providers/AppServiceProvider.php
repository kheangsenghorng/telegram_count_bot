<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\PayWay\AbaPayWayClient;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            AbaPayWayClient::class,
            function (): AbaPayWayClient {
                $publicKeyPath = config(
                    'payway.rsa_public_key_path'
                );

                if (! is_string($publicKeyPath)) {
                    throw new RuntimeException(
                        'PayWay RSA public key path is invalid.'
                    );
                }

                /*
                 * Support a relative path from the Laravel project root.
                 */
                if (! str_starts_with($publicKeyPath, '/')) {
                    $publicKeyPath = base_path($publicKeyPath);
                }

                if (! is_file($publicKeyPath)) {
                    throw new RuntimeException(
                        "PayWay RSA public key was not found: {$publicKeyPath}"
                    );
                }

                $publicKey = file_get_contents($publicKeyPath);

                if (
                    $publicKey === false
                    || trim($publicKey) === ''
                ) {
                    throw new RuntimeException(
                        'Unable to read the PayWay RSA public key.'
                    );
                }

                return new AbaPayWayClient(
                    merchantId: (string) config(
                        'payway.merchant_id'
                    ),
                    apiKey: (string) config(
                        'payway.api_key'
                    ),
                    rsaPublicKey: $publicKey,
                    baseUrl: (string) config(
                        'payway.base_url'
                    ),
                    connectTimeout: (int) config(
                        'payway.connect_timeout',
                        10
                    ),
                    timeout: (int) config(
                        'payway.timeout',
                        30
                    ),
                );
            }
        );
    }
}