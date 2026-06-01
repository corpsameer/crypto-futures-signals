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

## Telegram and Trading Notes

- There is no Telegram API integration in Phase 1. Signals will be manually pasted into a Laravel frontend form in a future phase.
- There is no live trading in Phase 1. Future trade tracking is simulated analysis only.

## Python Monitor Folder

`python_monitor/` is reserved for future CoinDCX price monitoring scripts that will call Laravel APIs. It currently contains only documentation and an `.env.example` file.
