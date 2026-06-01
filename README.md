# Crypto Futures Signal Analyzer

Crypto Futures Signal Analyzer is a Laravel-based self-use tool for tracking manually pasted crypto futures signals and reviewing simulated trade outcomes.

## Purpose

The application is intended to collect futures signal data, organize it, and later support analysis of simulated performance by signal source or trader. It is a separate codebase from the Lean Swing Assistant project.

## Phase 1 Scope

Phase 1 is limited to project bootstrap and tracking/data-collection preparation:

- Laravel application structure.
- Blade-based dashboard route under the project URL prefix.
- Base environment configuration for MySQL, CoinDCX settings, and a future Python API token.
- Placeholder folder for future Python price-monitoring scripts.

Phase 1 intentionally does **not** include database tables, a signal parser, Python monitoring logic, authentication, Telegram API integration, or live trading.

## Tech Stack

- PHP 8.2+
- Laravel 12
- Laravel Blade views
- MySQL
- Bootstrap CDN for simple responsive styling
- Future Python scripts for CoinDCX price monitoring

## Local Setup

1. Install PHP 8.2+, Composer, and MySQL.
2. Install Composer dependencies:

   ```bash
   composer install
   ```

3. Copy the environment file:

   ```bash
   cp .env.example .env
   ```

4. Generate the application key:

   ```bash
   php artisan key:generate
   ```

5. Create a local MySQL database named `crypto_futures_signals`.
6. Update `.env` if your MySQL username or password differs from the defaults.
7. Start the Laravel development server:

   ```bash
   php artisan serve
   ```

8. Open the dashboard:

   ```text
   http://localhost:8000/cryptofuturesignals/dashboard
   ```

## Route Prefix

All web pages for this project are grouped under:

```text
/cryptofuturesignals
```

Current bootstrap route:

```text
GET /cryptofuturesignals/dashboard
```

Planned future routes include:

- `/cryptofuturesignals/signals/create`
- `/cryptofuturesignals/signals`
- `/cryptofuturesignals/trades`
- `/cryptofuturesignals/trader-performance`

## Database

The project is configured for a separate MySQL database:

```text
crypto_futures_signals
```

No database tables or migrations are created in this bootstrap phase.

## Telegram and Trading Notes

- There is no Telegram API integration in Phase 1. Signals will be manually pasted into a Laravel frontend form in a future phase.
- There is no live trading in Phase 1. Future trade tracking is simulated analysis only.

## Python Monitor Folder

`python_monitor/` is reserved for future CoinDCX price monitoring scripts that will call Laravel APIs. It currently contains only documentation and an `.env.example` file.
