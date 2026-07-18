# Telegram Count Bot

Laravel application that listens to ABA Pay payment notifications in Telegram, records each transaction, and sends daily, weekly, and monthly income summaries back to Telegram.

The application also provides package/subscription management, ABA PayWay payments, realtime system monitoring, and Telegram group activity tracking.

## Requirements

-   PHP 8.2+
-   Composer
-   MySQL
-   Redis
-   Ngrok
-   A Telegram bot token from [@BotFather](https://t.me/BotFather)

## Setup

```bash
git clone https://github.com/kheangsenghorng/telegram_count_bot.git
cd telegram_count_bot
composer install
cp .env.example .env
php artisan key:generate
```

Configure your database and Telegram token in `.env`:

```env
DB_DATABASE=telegram_count_bot
DB_USERNAME=root
DB_PASSWORD=

TELEGRAM_BOT_TOKEN=your_bot_token_here
```

Configure Redis:

```env
CACHE_STORE=redis

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
```

Configure Laravel Reverb:

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=your_app_id
REVERB_APP_KEY=your_app_key
REVERB_APP_SECRET=your_app_secret

REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

Run migrations:

```bash
php artisan migrate
```

Clear Laravel cache:

```bash
php artisan optimize:clear
```

## Running Locally

Run each service in a separate terminal.

### Terminal 1 — Laravel API

```bash
php artisan serve
```

### Terminal 2 — Queue Worker

```bash
php artisan queue:work
```

### Terminal 3 — Scheduler

```bash
php artisan schedule:work
```

### Terminal 4 — Telegram Listener

Listen to Telegram groups as a user account using MadelineProto:

```bash
php artisan telegram:listen
```

### Terminal 5 — Laravel Reverb

Start the realtime WebSocket server:

```bash
php artisan reverb:start
```

For debugging:

```bash
php artisan reverb:start --debug
```

### Terminal 6 — System Status Broadcaster

Monitor system health and broadcast realtime updates:

```bash
php artisan system:status-broadcast
```

## Telegram Webhook

Start Ngrok:

```bash
ngrok http 8000
```

Point the Telegram webhook to your Ngrok URL:

```text
GET https://<your-ngrok-domain>/api/telegram/set-webhook
```

Check webhook status:

```text
GET https://<your-ngrok-domain>/api/telegram/webhook-info
```

## Realtime System Monitoring

The system uses Laravel Reverb to update the admin dashboard without refreshing the page.

The following services are monitored:

-   API Server
-   Database
-   Redis
-   Telegram Listener
-   Queue Worker
-   Scheduler

Telegram group monitoring includes:

-   Connected Groups
-   Online Groups
-   Offline Groups
-   Active Groups
-   Inactive Groups
-   Disconnected Groups

Realtime flow:

```text
Laravel
    ↓
SystemStatusService
    ↓
SystemStatusUpdated
    ↓
Laravel Reverb
    ↓
Laravel Echo
    ↓
Zustand
    ↓
Next.js Admin Dashboard
```

## Useful Commands

| Command                               | Purpose                                   |
| ------------------------------------- | ----------------------------------------- |
| `php artisan serve`                   | Start the Laravel development server      |
| `php artisan telegram:webhook`        | Manage the Telegram bot webhook           |
| `php artisan telegram:listen`         | Start the MadelineProto Telegram listener |
| `php artisan queue:work`              | Process queued jobs                       |
| `php artisan queue:restart`           | Restart queue workers                     |
| `php artisan queue:failed`            | Show failed queue jobs                    |
| `php artisan queue:retry all`         | Retry all failed queue jobs               |
| `php artisan schedule:work`           | Run Laravel scheduled tasks locally       |
| `php artisan reverb:start`            | Start Laravel Reverb                      |
| `php artisan reverb:start --debug`    | Start Reverb with debug output            |
| `php artisan reverb:restart`          | Restart Laravel Reverb                    |
| `php artisan system:status-broadcast` | Broadcast realtime system status changes  |
| `php artisan migrate`                 | Run database migrations                   |
| `php artisan migrate:status`          | Check migration status                    |
| `php artisan optimize:clear`          | Clear Laravel caches                      |
| `php artisan config:clear`            | Clear configuration cache                 |
| `php artisan route:clear`             | Clear route cache                         |
| `php artisan cache:clear`             | Clear application cache                   |

## API

| Route                                    | Purpose                           |
| ---------------------------------------- | --------------------------------- |
| `POST /api/telegram/webhook`             | Telegram webhook entry point      |
| `GET /api/telegram/webhook-info`         | Inspect Telegram webhook status   |
| `GET /api/system/status`                 | Get current system health status  |
| `POST /api/v1/payway/payment-links`      | Create an ABA PayWay payment link |
| `POST /api/v1/payway/transactions/check` | Check an ABA PayWay transaction   |

See `routes/api/*.php` for the full route list, including admin, owner, customer, authentication, subscription, and payment routes.

## Example Payment Message

```text
៛10,500 paid by DUONG SOKHA (*256) on Jun 20, 05:56 PM via ABA PAY at CHEN KHEANG. Trx. ID: 178195299977822, APV: 418335.
```

The application parses and stores:

-   Amount
-   Currency
-   Customer name
-   Payment method
-   Merchant name
-   Transaction ID
-   APV code
-   Payment timestamp
-   Telegram group
-   Raw message

## Production Commands

The following long-running processes should be managed by Supervisor in production:

```bash
php artisan queue:work
php artisan telegram:listen
php artisan reverb:start
php artisan system:status-broadcast
```

The Laravel scheduler should run through cron:

```cron
* * * * * cd /var/www/telegram_count_bot && php artisan schedule:run >> /dev/null 2>&1
```

## License

Open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Author

Developed by **Kheang SengHorng**.
