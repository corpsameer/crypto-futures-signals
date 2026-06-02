"""Runnable skeleton for the Phase 1 price monitor."""

import argparse
import logging
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
from trade_logic import (
    calculate_trade_metrics,
    detect_gain_milestone_events,
    detect_post_sl_events,
    detect_tp_sl_events,
    get_trade_direction,
    get_trade_entry_price,
    get_trade_symbol,
    is_post_sl_tracking_expired,
    normalize_symbol,
    parse_post_sl_tracking_until,
    safe_float,
    should_trigger_entry,
)

logger = logging.getLogger(__name__)


def main() -> int:
    parser = argparse.ArgumentParser(description="Crypto Futures Signal Analyzer price monitor skeleton.")
    parser.add_argument("--once", action="store_true", help="Run one monitor check and exit.")
    parser.add_argument(
        "--skip-config-check",
        action="store_true",
        help="Skip validation of placeholder local configuration for dry testing.",
    )
    args = parser.parse_args()

    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s: %(message)s")

    if not args.skip_config_check:
        try:
            require_config()
        except RuntimeError as exc:
            logger.error("Configuration error: %s", exc)
            return 1

    laravel_client = LaravelApiClient(LARAVEL_BASE_URL, PYTHON_API_TOKEN)
    coindcx_client = CoinDCXClient(COINDCX_MARKET_URL)

    print_config_summary(args.skip_config_check)

    if args.once:
        run_check(laravel_client, coindcx_client)
        return 0

    while True:
        try:
            logger.info("Running monitor check...")
            run_check(laravel_client, coindcx_client)
        except Exception as exc:
            logger.exception("Monitor check failed: %s", exc)

        time.sleep(POLL_INTERVAL_SECONDS)


def run_check(laravel_client: LaravelApiClient, coindcx_client: CoinDCXClient) -> None:
    pending_signals = extract_items(fetch_with_error("pending signals", laravel_client.get_pending_signals))
    logger.info("Pending signals count: %d", len(pending_signals))

    pending_symbols = collect_symbols(pending_signals)
    if pending_symbols:
        pending_prices = fetch_prices(coindcx_client, pending_symbols, "entry trigger checks")
        for signal in pending_signals:
            process_pending_signal(signal, pending_prices, laravel_client)
    else:
        logger.info("No pending signal symbols to price-check.")

    active_trades = extract_items(fetch_with_error("active simulated trades", laravel_client.get_active_trades))
    logger.info("Active trades count: %d", len(active_trades))

    active_symbols = collect_trade_symbols(active_trades)
    if active_symbols:
        active_prices = fetch_prices(coindcx_client, active_symbols, "active trade TP/SL tracking")
        for trade in active_trades:
            process_active_trade(trade, active_prices, laravel_client)
    else:
        logger.info("No active trade symbols to price-check.")

    post_sl_trades = extract_items(fetch_with_error("post-SL tracking trades", laravel_client.get_post_sl_tracking_trades))
    logger.info("Post-SL tracking trades count: %d", len(post_sl_trades))

    post_sl_symbols = collect_trade_symbols(post_sl_trades)
    if post_sl_symbols:
        post_sl_prices = fetch_prices(coindcx_client, post_sl_symbols, "post-SL tracking")
        for trade in post_sl_trades:
            process_post_sl_trade(trade, post_sl_prices, laravel_client)
    else:
        logger.info("No post-SL tracking trade symbols to price-check.")


def process_pending_signal(signal: dict, prices: dict, laravel_client: LaravelApiClient) -> None:
    signal_id = signal.get("id")
    symbol = normalize_symbol(signal.get("symbol") or signal.get("pair"))

    try:
        if not signal_id:
            logger.warning("Skipping pending signal without id: %s", signal)
            return

        if not symbol:
            logger.warning("Skipping pending signal %s without symbol.", signal_id)
            return

        price_data = prices.get(symbol)
        if not price_data or not price_data.get("found"):
            logger.warning("Price missing for pending signal %s symbol %s.", signal_id, symbol)
            return

        current_price = price_data.get("price")
        logger.info("Price found for pending signal %s symbol %s: %s", signal_id, symbol, current_price)

        trigger_result = should_trigger_entry(signal, current_price)
        if not trigger_result.get("triggered"):
            logger.info(
                "Waiting for entry on signal %s %s: %s",
                signal_id,
                symbol,
                trigger_result.get("reason"),
            )
            return

        logger.info(
            "Entry triggered for signal %s %s at fill price %s (current price %s).",
            signal_id,
            symbol,
            trigger_result.get("fill_price"),
            current_price,
        )

        payload = {
            "trade_signal_id": signal_id,
            "entry_price": trigger_result["fill_price"],
            "current_price": current_price,
            "event_timestamp": timestamp(),
            "actual_price_move_percent": trigger_result["actual_price_move_percent"],
            "leveraged_pnl_percent": trigger_result["leveraged_pnl_percent"],
        }
        response = laravel_client.entry_triggered(payload)
        logger.info("Laravel entry-triggered API succeeded for signal %s: %s", signal_id, summarize_response(response))
    except Exception as exc:
        logger.exception("Failed to process pending signal %s (%s): %s", signal_id, symbol or "unknown symbol", exc)



def process_active_trade(trade: dict, prices: dict, laravel_client: LaravelApiClient) -> None:
    trade_id = (trade.get("id") or trade.get("simulated_trade_id")) if isinstance(trade, dict) else None
    symbol = get_trade_symbol(trade)

    try:
        if not trade_id:
            logger.warning("Skipping active trade without id: %s", trade)
            return

        if not symbol:
            logger.warning("Skipping active trade %s without symbol.", trade_id)
            return

        price_data = prices.get(symbol) if isinstance(prices, dict) else None
        if not price_data or not price_data.get("found"):
            logger.warning("Price missing for active trade %s %s.", trade_id, symbol)
            return

        current_price = safe_float(price_data.get("price"))
        if current_price is None:
            logger.warning("Price invalid for active trade %s %s: %s", trade_id, symbol, price_data.get("price"))
            return

        logger.info("Price found for active trade %s %s: %s", trade_id, symbol, current_price)

        direction = get_trade_direction(trade)
        if direction not in {"LONG", "SHORT"}:
            logger.warning("Skipping active trade %s %s with missing/invalid direction: %s", trade_id, symbol, direction)
            return

        entry_price = get_trade_entry_price(trade)
        if entry_price is None or entry_price == 0:
            logger.warning("Skipping active trade %s %s metrics/events: missing or invalid entry_price.", trade_id, symbol)
            return

        trade_metrics = calculate_trade_metrics(trade, current_price)
        metrics_payload = {
            "simulated_trade_id": trade_id,
            "current_price": trade_metrics["current_price"],
            "actual_price_move_percent": trade_metrics["actual_price_move_percent"],
            "leveraged_pnl_percent": trade_metrics["leveraged_pnl_percent"],
            "price_timestamp": timestamp(),
        }

        try:
            response = laravel_client.update_metrics(metrics_payload)
            logger.info("Metrics updated for trade %s: %s", trade_id, summarize_response(response))
        except Exception as exc:
            logger.error("Metrics update failed for trade %s: %s", trade_id, exc)

        tp_sl_events = detect_tp_sl_events(trade, current_price)
        gain_milestone_events = detect_gain_milestone_events(trade, current_price)

        for event in gain_milestone_events:
            logger.info(
                "Detected event %s for trade %s at price %s, leveraged pnl %.2f%%",
                event["event_type"],
                trade_id,
                event["event_price"],
                event["leveraged_pnl_percent"],
            )

        for event in tp_sl_events + gain_milestone_events:
            store_trade_event(laravel_client, trade_id, event)
    except Exception as exc:
        logger.exception("Failed to process active trade %s (%s): %s", trade_id, symbol or "unknown symbol", exc)



def process_post_sl_trade(trade: dict, prices: dict, laravel_client: LaravelApiClient) -> None:
    trade_id = (trade.get("id") or trade.get("simulated_trade_id")) if isinstance(trade, dict) else None
    symbol = get_trade_symbol(trade)

    try:
        if not trade_id:
            logger.warning("Skipping post-SL trade without id: %s", trade)
            return

        if not symbol:
            logger.warning("Skipping post-SL trade %s without symbol.", trade_id)
            return

        price_data = prices.get(symbol) if isinstance(prices, dict) else None
        if not price_data or not price_data.get("found"):
            logger.warning("Price missing for post-SL trade %s %s.", trade_id, symbol)
            return

        current_price = safe_float(price_data.get("price"))
        if current_price is None:
            logger.warning("Price invalid for post-SL trade %s %s: %s", trade_id, symbol, price_data.get("price"))
            return

        logger.info("Price found for post-SL trade %s %s: %s", trade_id, symbol, current_price)

        direction = get_trade_direction(trade)
        if direction not in {"LONG", "SHORT"}:
            logger.warning("Skipping post-SL trade %s %s with missing/invalid direction: %s", trade_id, symbol, direction)
            return

        entry_price = get_trade_entry_price(trade)
        if entry_price is None or entry_price == 0:
            logger.warning("Skipping post-SL trade %s %s metrics/events: missing or invalid entry_price.", trade_id, symbol)
            return

        trade_metrics = calculate_trade_metrics(trade, current_price)
        event_time = timestamp()
        metrics_payload = {
            "simulated_trade_id": trade_id,
            "current_price": trade_metrics["current_price"],
            "actual_price_move_percent": trade_metrics["actual_price_move_percent"],
            "leveraged_pnl_percent": trade_metrics["leveraged_pnl_percent"],
            "price_timestamp": event_time,
        }

        try:
            response = laravel_client.update_metrics(metrics_payload)
            logger.info("Post-SL metrics updated for trade %s: %s", trade_id, summarize_response(response))
        except Exception as exc:
            logger.error("Post-SL metrics update failed for trade %s: %s", trade_id, exc)

        tracking_until = trade.get("tracking_until")
        if tracking_until and parse_post_sl_tracking_until(tracking_until) is None:
            logger.warning(
                "Post-SL tracking_until could not be parsed for trade %s (%s); continuing tracking safely.",
                trade_id,
                tracking_until,
            )
        elif is_post_sl_tracking_expired(trade, datetime.now(timezone.utc)):
            close_payload = {
                "simulated_trade_id": trade_id,
                "exit_price": current_price,
                "exit_reason": "POST_SL_TRACKING_COMPLETED",
                "status": "completed",
                "actual_price_move_percent": trade_metrics["actual_price_move_percent"],
                "leveraged_pnl_percent": trade_metrics["leveraged_pnl_percent"],
                "closed_at": event_time,
                "notes": "Post-SL tracking completed after configured tracking period",
            }
            try:
                response = laravel_client.close_trade(close_payload)
                logger.info("Post-SL tracking completed for trade %s: %s", trade_id, summarize_response(response))
            except Exception as exc:
                logger.error("Post-SL close failed for trade %s: %s", trade_id, exc)
            return

        for event in detect_post_sl_events(trade, current_price):
            logger.info(
                "Detected post-SL event %s for trade %s at price %s, leveraged pnl %.2f%%",
                event["event_type"],
                trade_id,
                event["event_price"],
                event["leveraged_pnl_percent"],
            )
            store_trade_event(laravel_client, trade_id, event)
    except Exception as exc:
        logger.exception("Failed to process post-SL trade %s (%s): %s", trade_id, symbol or "unknown symbol", exc)

def store_trade_event(laravel_client: LaravelApiClient, trade_id, event: dict) -> None:
    event_type = event["event_type"]
    event_payload = {
        "simulated_trade_id": trade_id,
        "event_type": event_type,
        "event_price": event["event_price"],
        "actual_price_move_percent": event["actual_price_move_percent"],
        "leveraged_pnl_percent": event["leveraged_pnl_percent"],
        "event_timestamp": event["event_timestamp"],
        "metadata": event["metadata"],
        "notes": event["notes"],
    }

    try:
        response = laravel_client.store_event(event_payload)
        logger.info(
            "Laravel event store succeeded for trade %s %s: %s",
            trade_id,
            event_type,
            summarize_response(response),
        )
    except Exception as exc:
        logger.error("Laravel event store failed for trade %s %s: %s", trade_id, event_type, exc)


def collect_symbols(signals: list[dict]) -> list[str]:
    symbols = []
    seen = set()

    for signal in signals:
        if not isinstance(signal, dict):
            continue

        symbol = normalize_symbol(signal.get("symbol") or signal.get("pair"))
        if not symbol or symbol in seen:
            continue

        symbols.append(symbol)
        seen.add(symbol)

    return symbols


def collect_trade_symbols(trades: list[dict]) -> list[str]:
    symbols = []
    seen = set()

    for trade in trades:
        symbol = get_trade_symbol(trade)
        if not symbol or symbol in seen:
            continue

        symbols.append(symbol)
        seen.add(symbol)

    return symbols


def fetch_prices(coindcx_client: CoinDCXClient, symbols: list[str], purpose: str) -> dict:
    prices = fetch_with_error("CoinDCX prices", lambda: coindcx_client.get_prices_for_symbols(symbols))
    if not isinstance(prices, dict):
        logger.warning("CoinDCX prices response was not a dictionary; skipping %s.", purpose)
        return {}

    return prices


def extract_items(value) -> list:
    if value is None:
        return []

    if isinstance(value, list):
        return value

    if isinstance(value, dict):
        for key in ("data", "items", "results"):
            if isinstance(value.get(key), list):
                return value[key]

    return []


def fetch_with_error(label: str, callback):
    try:
        return callback()
    except Exception as exc:
        logger.error("Could not fetch %s: %s", label, exc)
        return []


def summarize_response(response) -> str:
    if not isinstance(response, dict):
        return str(response)

    if "message" in response:
        return str(response["message"])

    if "success" in response:
        return f"success={response['success']}"

    return "response received"


def print_config_summary(skip_config_check: bool) -> None:
    logger.info("Crypto Futures Signal Analyzer Python monitor")
    logger.info("Laravel base URL: %s", LARAVEL_BASE_URL)
    logger.info("CoinDCX market URL: %s", COINDCX_MARKET_URL)
    logger.info("Poll interval seconds: %s", POLL_INTERVAL_SECONDS)
    logger.info("Entry valid hours: %s", ENTRY_VALID_HOURS)
    logger.info("Post-SL tracking days: %s", POST_SL_TRACKING_DAYS)
    logger.info("API token configured: %s", "yes" if bool(PYTHON_API_TOKEN) else "no")
    logger.info("Config validation skipped: %s", "yes" if skip_config_check else "no")


def timestamp() -> str:
    return datetime.now(timezone.utc).isoformat()


if __name__ == "__main__":
    raise SystemExit(main())
