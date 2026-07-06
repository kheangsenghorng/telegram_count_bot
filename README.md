# Telegram Count Bot

Laravel application that listens to ABA Pay payment notifications in Telegram, records each transaction, and sends daily, weekly, and monthly income summaries back to Telegram. It also exposes a KHQR payment API and a package/subscription checkout flow.

## Requirements

- PHP 8.2+
- Composer
- MySQL
- Ngrok (for exposing the webhook locally)
- A Telegram bot token from [@BotFather](https://t.me/BotFather)

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

Run migrations:

```bash
php artisan migrate
```

## Running locally

Start the app and expose it with Ngrok:

```bash
php artisan serve
ngrok http 8000
```

Point the Telegram webhook at your Ngrok URL:

```bash
GET https://<your-ngrok-domain>/api/telegram/set-webhook
```

Run the queue and scheduler in separate terminals:

```bash
php artisan queue:work
php artisan schedule:work
```

Optionally, listen to a Telegram group as a user account (via MadelineProto) instead of the bot webhook:

```bash
php artisan telegram:listen
```

## Useful commands

| Command | Purpose |
|---|---|
| `php artisan telegram:webhook` | Manage the Telegram bot webhook |
| `php artisan telegram:listen` | Listen for ABA Pay messages as a Telegram user |
| `php artisan queue:work` | Process queued jobs (summaries, notifications) |
| `php artisan schedule:work` | Run scheduled jobs (day/week/month summaries, stale-transaction pruning) |
| `php artisan optimize:clear` | Clear cached config, routes, and views |

## API

| Route | Purpose |
|---|---|
| `POST /api/telegram/webhook` | Telegram webhook entry point |
| `GET /api/telegram/webhook-info` | Inspect current webhook status |
| `POST /api/v1/khqr/merchant` | Generate a merchant KHQR code |
| `POST /api/v1/khqr/individual` | Generate an individual KHQR code |
| `POST /api/v1/khqr/check-transaction-by-md5` | Verify a KHQR transaction |
| `GET /api/v1/khqr/payment-checkout/{transactionId}` | View a payment checkout page |

See `routes/api/*.php` for the full route list (admin, owner, customer, auth).

## Example payment message

```text
៛10,500 paid by DUONG SOKHA (*256) on Jun 20, 05:56 PM via ABA PAY at CHEN KHEANG. Trx. ID: 178195299977822, APV: 418335.
```

The bot parses and stores the amount, customer name, payment method, store name, transaction ID, APV code, and timestamp.

## License

Open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Author

Developed by **Kheang SengHorng**.
