<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Telegram\AbaTelegramHandler;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Logger as LoggerSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ListenAbaTelegram extends Command
{
    protected $signature   = 'telegram:listen';
    protected $description = 'Listen for ABA Pay messages as a Telegram user (MadelineProto)';

    public function handle(): int
    {
        $this->info('');
        $this->info('  🤖 Starting MadelineProto ABA listener...');
        $this->info('  ─────────────────────────────────────────');
        $this->info('  Logs in as a REAL Telegram user account.');
        $this->info('  Can read messages from ALL bots (including ABA bot).');
        $this->info('');
        $this->warn('  ⚠  First run: you will be asked for your phone number + OTP.');
        $this->info('  Session saved to: ' . storage_path('app/madeline/aba-session.madeline'));
        $this->info('');

        $apiId   = (int)    config('services.telegram.api_id');
        $apiHash = (string) config('services.telegram.api_hash');

        if (! $apiId || ! $apiHash) {
            $this->error('  ❌ TELEGRAM_API_ID or TELEGRAM_API_HASH is missing in .env');
            $this->line('  Get them at: https://my.telegram.org/apps');

            return self::FAILURE;
        }

        // Create session directory BEFORE building settings,
        // in case MadelineProto tries to write to it during setup
        $sessionPath = storage_path('app/madeline');

        if (! is_dir($sessionPath)) {
            if (! mkdir($sessionPath, 0755, true) && ! is_dir($sessionPath)) {
                $this->error("  ❌ Could not create session directory: {$sessionPath}");

                return self::FAILURE;
            }
        }

        $appInfo = new AppInfo();
        $appInfo->setApiId($apiId);
        $appInfo->setApiHash($apiHash);

        $settings = new Settings();
        $settings->setAppInfo($appInfo);

        // Quiet MadelineProto's internal MTProto logging — errors only, to file.
        // Your handler's own echo/Log lines are unaffected.
        $logger = new LoggerSettings();
        $logger->setType(Logger::FILE_LOGGER);
        $logger->setExtra(storage_path('logs/madeline.log'));
        $logger->setLevel(Logger::LEVEL_ERROR);

        $settings->setLogger($logger);

        $this->info('  ✅ Starting event loop — press Ctrl+C to stop.');
        $this->info('');

        // Initial heartbeat so the dashboard goes green immediately on boot,
        // before the first periodic beat fires inside the handler.
        Cache::put('heartbeat:telegram_listener', now()->timestamp, 300);

        // Wrap in try/catch — startAndLoop() runs forever and any
        // uncaught exception would otherwise kill the process silently
        try {
            AbaTelegramHandler::startAndLoop(
                storage_path('app/madeline/aba-session.madeline'),
                $settings
            );
        } catch (\Throwable $e) {
            $this->error('  ❌ Listener crashed: ' . $e->getMessage());
            $this->line('  File: ' . $e->getFile() . ':' . $e->getLine());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}