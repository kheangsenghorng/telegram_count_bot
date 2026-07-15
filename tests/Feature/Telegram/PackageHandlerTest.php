<?php

declare(strict_types=1);

namespace Tests\Feature\Telegram;

use App\Http\Controllers\Api\Telegram\PackageHandler;
use App\Models\Package;
use App\Services\Khqr\BakongService;
use App\Services\TelegramBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Run: php artisan test --filter=PackageHandlerTest
 *
 * Requires in phpunit.xml (usually already set):
 *   <env name="CACHE_STORE" value="array"/>
 *   <env name="DB_CONNECTION" value="sqlite"/>
 *   <env name="DB_DATABASE" value=":memory:"/>
 */
class PackageHandlerTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $telegram;
    private MockInterface $bakong;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->telegram = $this->mock(TelegramBotService::class);
        $this->bakong   = $this->mock(BakongService::class);
    }

    private function handler(): PackageHandler
    {
        return app(PackageHandler::class);
    }

    private function makePackage(array $overrides = []): Package
    {
        return Package::query()->create(array_merge([
            'packagesID'    => (string) \Illuminate\Support\Str::uuid(),
            'name'          => 'Basic',
            'price'         => 5.00,
            'billing_cycle' => 'monthly',
            'group_limit'   => 1,
            'payment_limit' => 100,
            'status'        => 'active',
            // ← ADJUST: add any other NOT NULL columns your packages table has
        ], $overrides));
    }

    /* =====================================================
     * showPackages
     * ===================================================== */

    public function test_show_packages_blocks_non_private_chat(): void
    {
        $this->telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($chatId, $text) => str_contains($text, 'Private Chat'));

        $response = $this->handler()->showPackages('12345', 'group');

        $this->assertTrue($response->getData(true)['ok']);
    }

    public function test_show_packages_sends_header_and_one_message_per_package(): void
    {
        $this->makePackage(['name' => 'Basic',   'price' => 5]);
        $this->makePackage(['name' => 'Premium', 'price' => 15]);

        // header + 2 packages = at least 3 sends (+ optional support footer)
        $this->telegram->shouldReceive('sendMessage')->atLeast()->times(3);

        $this->handler()->showPackages('12345', 'private');

        // Package list must now be cached
        $this->assertTrue(Cache::has(PackageHandler::packagesListKey()));
    }

    public function test_show_packages_serves_second_call_from_cache(): void
    {
        $this->makePackage();

        $this->telegram->shouldReceive('sendMessage')->atLeast()->once();

        $this->handler()->showPackages('111', 'private');

        // Delete the package directly in DB — cache should still hold it
        Package::query()->delete();

        // Different chat id (so the double-tap lock doesn't block)
        $this->handler()->showPackages('222', 'private');

        $cached = Cache::get(PackageHandler::packagesListKey());
        $this->assertCount(1, $cached, 'Second call must be served from Redis, not DB');
    }

    public function test_show_packages_double_tap_is_blocked_by_lock(): void
    {
        $this->makePackage();

        // First tap sends messages…
        $this->telegram->shouldReceive('sendMessage')->atLeast()->once();

        $this->handler()->showPackages('12345', 'private');

        // …second tap within 3s must send NOTHING (lock hit)
        $this->telegram->shouldReceive('sendMessage')->never();

        $response = $this->handler()->showPackages('12345', 'private');
        $this->assertTrue($response->getData(true)['ok']);
    }

    public function test_show_packages_with_no_packages_sends_empty_notice(): void
    {
        $this->telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($chatId, $text) => str_contains($text, 'គ្មានកញ្ចប់'));

        $this->handler()->showPackages('12345', 'private');
    }

    /* =====================================================
     * handleBuyCallback
     * ===================================================== */

    public function test_buy_unknown_package_edits_message_with_not_found(): void
    {
        $this->telegram->shouldReceive('editMessage')
            ->once()
            ->withArgs(fn ($chatId, $msgId, $text) => str_contains($text, 'មិនមានទេ'));

        $this->handler()->handleBuyCallback('123', 1, 'pkg_buy_nonexistent-id', 'tester', 'private');
    }

    public function test_buy_inactive_package_is_rejected(): void
    {
        $pkg = $this->makePackage(['status' => 'inactive']);

        $this->telegram->shouldReceive('editMessage')
            ->once()
            ->withArgs(fn ($chatId, $msgId, $text) => str_contains($text, 'មិនអាចទិញបានទេ'));

        $this->handler()->handleBuyCallback('123', 1, 'pkg_buy_' . $pkg->packagesID, 'tester', 'private');
    }

    public function test_buy_success_creates_checkout_and_shows_pay_button(): void
    {
        $pkg = $this->makePackage();

        $payment = Mockery::mock();
        $payment->shouldReceive('forceFill')->once()->andReturnSelf();
        $payment->shouldReceive('save')->once()->andReturnTrue();
        $payment->shouldReceive('getAttribute')->andReturnNull(); // external_transaction_id etc.
        $payment->external_transaction_id = 'INV-001';

        $this->bakong->shouldReceive('createCheckout')->once()->andReturn($payment);
        $this->bakong->shouldReceive('checkoutUrl')->once()->andReturn('https://pay.example/khqr/abc');

        $this->telegram->shouldReceive('sendChatAction')->once();
        $this->telegram->shouldReceive('editMessage')
            ->once()
            ->withArgs(function ($chatId, $msgId, $text, $keyboard) {
                return str_contains($text, 'បញ្ជាក់ការទិញ')
                    && $keyboard[0][0]['url'] === 'https://pay.example/khqr/abc';
            });

        $this->handler()->handleBuyCallback('123', 1, 'pkg_buy_' . $pkg->packagesID, 'tester', 'private');
    }

    public function test_buy_checkout_failure_shows_error_message(): void
    {
        $pkg = $this->makePackage();

        $this->bakong->shouldReceive('createCheckout')
            ->once()
            ->andThrow(new \RuntimeException('Bakong down'));

        $this->telegram->shouldReceive('sendChatAction')->once();
        $this->telegram->shouldReceive('editMessage')
            ->once()
            ->withArgs(fn ($chatId, $msgId, $text) => str_contains($text, 'មិនអាចបង្កើតការទូទាត់'));

        $this->handler()->handleBuyCallback('123', 1, 'pkg_buy_' . $pkg->packagesID, 'tester', 'private');
    }

    public function test_buy_double_tap_is_blocked_by_lock(): void
    {
        $pkg = $this->makePackage();

        $payment = Mockery::mock();
        $payment->shouldReceive('forceFill')->andReturnSelf();
        $payment->shouldReceive('save')->andReturnTrue();
        $payment->external_transaction_id = null;

        // Exactly ONE checkout even though the button is tapped twice
        $this->bakong->shouldReceive('createCheckout')->once()->andReturn($payment);
        $this->bakong->shouldReceive('checkoutUrl')->once()->andReturn('https://pay.example/x');

        $this->telegram->shouldReceive('sendChatAction')->once();
        $this->telegram->shouldReceive('editMessage')->once();

        $this->handler()->handleBuyCallback('123', 1, 'pkg_buy_' . $pkg->packagesID, 'tester', 'private');
        $this->handler()->handleBuyCallback('123', 1, 'pkg_buy_' . $pkg->packagesID, 'tester', 'private');
    }

    /* =====================================================
     * Cache invalidation
     * ===================================================== */

    public function test_invalidate_packages_forgets_list_and_row(): void
    {
        Cache::put(PackageHandler::packagesListKey(), 'x', 60);
        Cache::put(PackageHandler::packageKey('abc'), 'y', 60);

        PackageHandler::invalidatePackages('abc');

        $this->assertFalse(Cache::has(PackageHandler::packagesListKey()));
        $this->assertFalse(Cache::has(PackageHandler::packageKey('abc')));
    }

    public function test_invalidate_subscription_forgets_key(): void
    {
        Cache::put(PackageHandler::subscriptionKey('uuid-1'), 'x', 60);

        PackageHandler::invalidateSubscription('uuid-1');

        $this->assertFalse(Cache::has(PackageHandler::subscriptionKey('uuid-1')));
    }
}