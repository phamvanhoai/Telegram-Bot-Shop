# Telegram Bot Shop

Telegram digital-product shop built with PHP 8.4, Laravel 13 and MySQL 8.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
php artisan serve
```

Set `TELEGRAM_BOT_TOKEN` and a random `TELEGRAM_WEBHOOK_SECRET`, then register the HTTPS endpoint `/webhooks/telegram` with Telegram and pass the same secret as `secret_token`.

Point the Apache document root to `public`. Production must run Laravel Scheduler every minute and a queue worker. Supervisor is preferred; cron with `--stop-when-empty` is the shared-hosting fallback.

## Automatic deployment

`public/deploy-github.php` receives signed GitHub push webhooks for `main` and calls `/usr/local/sbin/telegram-shop-deploy-trigger`. Keep the webhook secret at `storage/app/.webhook-secret` and configure a narrowly scoped sudo rule. Application credentials remain in the server-side `.env` and are never committed.
