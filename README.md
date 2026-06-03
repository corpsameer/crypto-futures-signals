# Crypto Futures Signal Analyzer

Crypto Futures Signal Analyzer is a Laravel-based self-use tool for tracking manually pasted crypto futures signals and reviewing simulated trade outcomes.

## Purpose

The application is intended to collect futures signal data, organize it, and later support analysis of simulated performance by signal source or trader. It is a separate codebase from the Lean Swing Assistant project.

## Phase 1 Scope

Phase 1 is limited to project bootstrap and authentication lockdown for self-use:

- Laravel application structure.
- Blade-based dashboard route under the project URL prefix.
- Simple Laravel session-based login for pre-created users.
- Base environment configuration for MySQL, CoinDCX settings, admin seeding, and a future Python API token.
- Placeholder folder for future Python price-monitoring scripts.

Phase 1 intentionally does **not** include signal tables, a signal parser, Python monitoring logic, Telegram API integration, or live trading.

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
7. Keep the framework drivers database-backed for local development:

   ```dotenv
   SESSION_DRIVER=database
   CACHE_STORE=database
   QUEUE_CONNECTION=database
   ```

   The standard framework migrations in this repo create the required `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, and `failed_jobs` tables.

8. Configure the seeded admin login in `.env` if you do not want to use the example values:

   ```dotenv
   ADMIN_NAME="Admin"
   ADMIN_EMAIL=admin@example.com
   ADMIN_PASSWORD=password
   ```

9. Run the database migrations:

   ```bash
   php artisan migrate
   ```

   For a clean local reset with no data to keep, you can use:

   ```bash
   php artisan migrate:fresh
   ```

10. Seed the admin user:

   ```bash
   php artisan db:seed
   ```

   The seeder creates a user for `ADMIN_EMAIL`, or updates that existing user's name and hashed password.

11. Start the Laravel development server:

    ```bash
    php artisan serve
    ```

12. Open the login page and sign in with the seeded admin credentials:

    ```text
    http://localhost:8000/cryptofuturesignals/login
    ```

13. After login, the dashboard is available at:

    ```text
    http://localhost:8000/cryptofuturesignals/dashboard
    ```

## Authentication

The app uses Laravel's built-in session authentication with Blade views. Registration is intentionally disabled: there are no public registration routes, pages, or user-creation endpoints. Users should be pre-created through the admin seeder or another trusted internal process.

All `/cryptofuturesignals` app pages are auth-protected except the login routes. Future app pages should be added inside the existing `auth` middleware group in `routes/web.php`.

## Route Prefix

All web pages for this project are grouped under:

```text
/cryptofuturesignals
```

Current routes:

```text
GET  /cryptofuturesignals/login
POST /cryptofuturesignals/login
POST /cryptofuturesignals/logout
GET  /cryptofuturesignals/dashboard
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

The current migration set creates Laravel's standard framework tables for auth, password reset tokens, database sessions, database cache, and database queues:

- `users`
- `password_reset_tokens`
- `sessions`
- `cache`
- `cache_locks`
- `jobs`
- `job_batches`
- `failed_jobs`

Indexed string columns are limited to 191 characters where needed so primary and unique indexes work on older MySQL/MariaDB configurations with a 1000-byte index key limit.

## Troubleshooting Fresh Setup

### `php artisan optimize:clear` says the `cache` table does not exist

Run the framework migrations so the database-backed cache table exists:

```bash
php artisan migrate
```

Then rerun:

```bash
php artisan optimize:clear
```

If you do not have local data to keep, `php artisan migrate:fresh` is also acceptable.

### `php artisan migrate` fails with `Specified key was too long`

Pull the latest migration changes first. The indexed string columns are now `191` characters where needed to support older MySQL/MariaDB index limits. If the failed migration left a partial `users` table behind and you do not have data to keep, run `php artisan migrate:fresh` or drop that partial table and rerun migrations:

```sql
DROP TABLE users;
```

```bash
php artisan migrate
```


## Python API Token

Laravel API endpoints intended for future Python scripts are protected with a shared token header:

```text
X-PYTHON-API-TOKEN: value_from_env
```

The expected token value comes from `PYTHON_API_TOKEN` in `.env`. The example environment file includes:

```dotenv
PYTHON_API_TOKEN=change_me_secure_token
```

The current token-protected health endpoint is available at:

```text
/api/cryptofuturesignals/api/health
```

Without a token, the endpoint should return HTTP 401:

```bash
curl http://127.0.0.1:8000/api/cryptofuturesignals/api/health
```

With an invalid token, the endpoint should return HTTP 403:

```bash
curl -H "X-PYTHON-API-TOKEN: wrong" http://127.0.0.1:8000/api/cryptofuturesignals/api/health
```

With the valid example token, the endpoint should return HTTP 200 with `success: true`:

```bash
curl -H "X-PYTHON-API-TOKEN: change_me_secure_token" http://127.0.0.1:8000/api/cryptofuturesignals/api/health
```


## Python Monitor API Endpoints

All Python monitor routes live under Laravel's API prefix and the project prefix, so the local URLs use this structure:

```text
/api/cryptofuturesignals/api/...
```

Every request must include the shared token header:

```text
X-PYTHON-API-TOKEN: change_me_secure_token
```

### Pending trade signals

Fetch structured signals waiting for an entry trigger:

```bash
curl -H "X-PYTHON-API-TOKEN: change_me_secure_token" \
  "http://127.0.0.1:8000/api/cryptofuturesignals/api/trade-signals/pending?limit=100&symbol=BTCUSDT"
```

Endpoint:

```text
GET /api/cryptofuturesignals/api/trade-signals/pending
```

### Active simulated trades

Fetch active simulated trades that need normal monitoring:

```bash
curl -H "X-PYTHON-API-TOKEN: change_me_secure_token" \
  "http://127.0.0.1:8000/api/cryptofuturesignals/api/simulated-trades/active?limit=100&symbol=BTCUSDT"
```

Endpoint:

```text
GET /api/cryptofuturesignals/api/simulated-trades/active
```

### Post-SL tracking trades

Fetch simulated trades that hit stop loss but are still being tracked for post-SL TP hits:

```bash
curl -H "X-PYTHON-API-TOKEN: change_me_secure_token" \
  "http://127.0.0.1:8000/api/cryptofuturesignals/api/simulated-trades/post-sl-tracking?limit=100&symbol=BTCUSDT"
```

Endpoint:

```text
GET /api/cryptofuturesignals/api/simulated-trades/post-sl-tracking
```

### Entry triggered

Record that a pending signal entry has triggered. Repeating the same `trade_signal_id` reuses the existing simulated trade and updates the existing `ENTRY_TRIGGERED` tracking event.

```bash
curl -X POST \
  -H "X-PYTHON-API-TOKEN: change_me_secure_token" \
  -H "Content-Type: application/json" \
  -d '{
    "trade_signal_id": 1,
    "entry_price": 65050,
    "current_price": 65050,
    "event_timestamp": "2026-06-01 12:30:00",
    "actual_price_move_percent": 0,
    "leveraged_pnl_percent": 0
  }' \
  http://127.0.0.1:8000/api/cryptofuturesignals/api/simulated-trades/entry-triggered
```

Endpoint:

```text
POST /api/cryptofuturesignals/api/simulated-trades/entry-triggered
```

### Store trade event

Store or update an idempotent trade tracking event. The same `simulated_trade_id` and `event_type` pair updates the existing event instead of creating a duplicate.

```bash
curl -X POST \
  -H "X-PYTHON-API-TOKEN: change_me_secure_token" \
  -H "Content-Type: application/json" \
  -d '{
    "simulated_trade_id": 10,
    "event_type": "TP1_HIT",
    "event_price": 66000,
    "actual_price_move_percent": 1.46,
    "leveraged_pnl_percent": 7.30,
    "event_timestamp": "2026-06-01 13:10:00",
    "metadata": {},
    "notes": "TP1 hit"
  }' \
  http://127.0.0.1:8000/api/cryptofuturesignals/api/trade-events/store
```

Endpoint:

```text
POST /api/cryptofuturesignals/api/trade-events/store
```

### Update simulated trade metrics

Update current price and max/min movement metrics without creating a tracking event:

```bash
curl -X POST \
  -H "X-PYTHON-API-TOKEN: change_me_secure_token" \
  -H "Content-Type: application/json" \
  -d '{
    "simulated_trade_id": 10,
    "current_price": 65100,
    "actual_price_move_percent": 0.08,
    "leveraged_pnl_percent": 0.40,
    "price_timestamp": "2026-06-01 12:35:00"
  }' \
  http://127.0.0.1:8000/api/cryptofuturesignals/api/simulated-trades/update-metrics
```

Endpoint:

```text
POST /api/cryptofuturesignals/api/simulated-trades/update-metrics
```

### Close simulated trade

Close a simulated trade and upsert the `TRADE_CLOSED` tracking event:

```bash
curl -X POST \
  -H "X-PYTHON-API-TOKEN: change_me_secure_token" \
  -H "Content-Type: application/json" \
  -d '{
    "simulated_trade_id": 10,
    "exit_price": 66000,
    "exit_reason": "TP_HIT",
    "status": "closed_tp",
    "actual_price_move_percent": 1.46,
    "leveraged_pnl_percent": 7.30,
    "closed_at": "2026-06-01 13:10:00",
    "notes": "Closed at TP"
  }' \
  http://127.0.0.1:8000/api/cryptofuturesignals/api/simulated-trades/close
```

Endpoint:

```text
POST /api/cryptofuturesignals/api/simulated-trades/close
```

### Store market snapshot

Store optional market context snapshots for a signal or simulated trade:

```bash
curl -X POST \
  -H "X-PYTHON-API-TOKEN: change_me_secure_token" \
  -H "Content-Type: application/json" \
  -d '{
    "trade_signal_id": 1,
    "simulated_trade_id": 10,
    "symbol": "BTCUSDT",
    "snapshot_type": "periodic",
    "price": 65100,
    "volume_24h": 1234567.89,
    "price_change_24h_percent": 2.5,
    "funding_rate": 0.01,
    "open_interest": 1000000,
    "raw_payload": {},
    "snapshot_at": "2026-06-01 12:35:00"
  }' \
  http://127.0.0.1:8000/api/cryptofuturesignals/api/market-snapshots/store
```

Endpoint:

```text
POST /api/cryptofuturesignals/api/market-snapshots/store
```

## Telegram and Trading Notes

- There is no Telegram API integration in Phase 1. Signals will be manually pasted into a Laravel frontend form in a future phase.
- There is no live trading in Phase 1. Future trade tracking is simulated analysis only.

## Python Monitor Folder

`python_monitor/` is a legacy placeholder. The active Phase 1 Python monitor skeleton now lives in `python/`.

## Python Monitor Setup

The initial Phase 1 Python monitor skeleton lives in `python/`. It reads configuration from `python/.env` and uses the Laravel API token header `X-PYTHON-API-TOKEN`.

Copy the Python environment example file:

```bash
cp python/.env.example python/.env
```

Edit `python/.env` and set `PYTHON_API_TOKEN` to match the Laravel `.env` token.

Install Python dependencies manually:

```bash
pip install -r python/requirements.txt
```

Run a one-time monitor check:

```bash
python python/price_monitor.py --once
```

Run a one-time monitor check with temporary config validation bypass for local dry testing before setting a real token:

```bash
python python/price_monitor.py --once --skip-config-check
```

Phase 1 Python monitoring is data-collection setup only: no live trading, no authenticated CoinDCX API calls, and no Telegram API integration.

## Running the Python Monitor with Supervisor

Use Supervisor to keep the Python monitor running continuously on a server. Do **not** install Supervisor automatically from this project, and do **not** edit server files directly from the application codebase. Review the example config first, then copy it manually on the server.

The example Supervisor config is available at:

```text
deploy/supervisor/crypto-futures-monitor.conf.example
```

It uses `/var/www/crypto-futures-signals` as the project path placeholder and runs:

```bash
/usr/bin/python3 /var/www/crypto-futures-signals/python/price_monitor.py
```

The monitor itself polls CoinDCX every 2 seconds. Do **not** schedule it every minute via cron for price fetching; Supervisor should keep the long-running monitor process alive. Cron may be used only as an optional watchdog later, not as the price-fetching scheduler.

On the server, copy the reviewed example config into Supervisor's config directory:

```bash
sudo cp deploy/supervisor/crypto-futures-monitor.conf.example /etc/supervisor/conf.d/crypto-futures-monitor.conf
```

Ask Supervisor to read and apply the new config:

```bash
sudo supervisorctl reread
sudo supervisorctl update
```

Start the monitor process:

```bash
sudo supervisorctl start crypto-futures-monitor
```

Check process status:

```bash
sudo supervisorctl status
```

Restart the monitor after code or configuration changes:

```bash
sudo supervisorctl restart crypto-futures-monitor
```

Follow the monitor's Supervisor output log:

```bash
sudo supervisorctl tail -f crypto-futures-monitor
```
