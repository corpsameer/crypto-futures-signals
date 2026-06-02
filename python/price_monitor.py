"""Runnable skeleton for the Phase 1 price monitor."""

import argparse
import time
from datetime import datetime, timezone

from coindcx_client import CoinDCXClient
from constants import (
    COINDCX_MARKET_URL,
    ENTRY_VALID_HOURS,
    LARAVEL_BASE_URL,
    POLL_INTERVAL_SECONDS,
    POST_SL_TRACKING_DAYS,
    PYTHON_API_TOKEN,
    require_config,
)
from laravel_api_client import LaravelApiClient


def main() -> int:
    parser = argparse.ArgumentParser(description="Crypto Futures Signal Analyzer price monitor skeleton.")
    parser.add_argument("--once", action="store_true", help="Run one monitor check and exit.")
    parser.add_argument(
        "--skip-config-check",
        action="store_true",
        help="Skip validation of placeholder local configuration for dry testing.",
    )
    args = parser.parse_args()

    if not args.skip_config_check:
        try:
            require_config()
        except RuntimeError as exc:
            print(f"Configuration error: {exc}")
            return 1

    laravel_client = LaravelApiClient(LARAVEL_BASE_URL, PYTHON_API_TOKEN)
    coindcx_client = CoinDCXClient(COINDCX_MARKET_URL)

    print_config_summary(args.skip_config_check)

    if args.once:
        run_check(laravel_client, coindcx_client)
        return 0

    while True:
        try:
            print(f"[{timestamp()}] Running monitor check...")
            run_check(laravel_client, coindcx_client)
        except Exception as exc:
            print(f"[{timestamp()}] Monitor check failed: {exc}")

        time.sleep(POLL_INTERVAL_SECONDS)


def run_check(laravel_client: LaravelApiClient, coindcx_client: CoinDCXClient) -> None:
    pending_signals = fetch_with_error("pending signals", laravel_client.get_pending_signals)
    active_trades = fetch_with_error("active trades", laravel_client.get_active_trades)
    post_sl_trades = fetch_with_error("post-SL tracking trades", laravel_client.get_post_sl_tracking_trades)
    price_map = fetch_with_error("CoinDCX price map", coindcx_client.get_price_map)

    print(f"[{timestamp()}] Pending signals count: {count_items(pending_signals)}")
    print(f"[{timestamp()}] Active trades count: {count_items(active_trades)}")
    print(f"[{timestamp()}] Post-SL trades count: {count_items(post_sl_trades)}")
    print(f"[{timestamp()}] Price map count: {count_items(price_map)}")


def fetch_with_error(label: str, callback):
    try:
        return callback()
    except Exception as exc:
        print(f"[{timestamp()}] Could not fetch {label}: {exc}")
        return []


def count_items(value) -> int:
    if value is None:
        return 0

    if isinstance(value, list):
        return len(value)

    if isinstance(value, dict):
        for key in ("data", "items", "results"):
            if isinstance(value.get(key), list):
                return len(value[key])

        return len(value)

    return 0


def print_config_summary(skip_config_check: bool) -> None:
    print("Crypto Futures Signal Analyzer Python monitor")
    print(f"Laravel base URL: {LARAVEL_BASE_URL}")
    print(f"CoinDCX market URL: {COINDCX_MARKET_URL}")
    print(f"Poll interval seconds: {POLL_INTERVAL_SECONDS}")
    print(f"Entry valid hours: {ENTRY_VALID_HOURS}")
    print(f"Post-SL tracking days: {POST_SL_TRACKING_DAYS}")
    print(f"API token configured: {'yes' if bool(PYTHON_API_TOKEN) else 'no'}")
    print(f"Config validation skipped: {'yes' if skip_config_check else 'no'}")


def timestamp() -> str:
    return datetime.now(timezone.utc).isoformat()


if __name__ == "__main__":
    raise SystemExit(main())
