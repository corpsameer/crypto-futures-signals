"""Runnable skeleton for the Phase 1 price monitor."""

import argparse
import time
from datetime import datetime, timezone

from coindcx_client import CoinDCXClient
from constants import (
    COINDCX_MARKET_URL,
    ENTRY_VALID_HOURS,
    LARAVEL_BASE_URL,
    LARAVEL_REFRESH_INTERVAL_SECONDS,
    POLL_INTERVAL_SECONDS,
    POST_SL_TRACKING_DAYS,
    PRICE_POLL_INTERVAL_SECONDS,
    PYTHON_API_TOKEN,
    require_config,
)
from laravel_api_client import LaravelApiClient
from logger_config import get_error_logger, get_monitor_logger
from trade_logic import (
    calculate_trade_metrics,
    detect_gain_milestone_events,
    detect_post_sl_events,
    detect_tp_sl_events,
    get_trade_direction,
    get_trade_entry_price,
    get_trade_symbol,
    is_entry_expired,
    is_post_sl_tracking_expired,
    normalize_symbol,
    parse_post_sl_tracking_until,
    safe_float,
    should_trigger_entry,
)

logger = get_monitor_logger()
error_logger = get_error_logger()


def main() -> int:
    parser = argparse.ArgumentParser(description="Crypto Futures Signal Analyzer price monitor.")
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
            logger.error("Configuration error: %s", exc)
            return 1

    laravel_client = LaravelApiClient(LARAVEL_BASE_URL, PYTHON_API_TOKEN)
    coindcx_client = CoinDCXClient(COINDCX_MARKET_URL)

    print_config_summary(args.skip_config_check)

    if args.once:
        run_check(laravel_client, coindcx_client, mode="once")
        return 0

    run_continuous_monitor(laravel_client, coindcx_client)
    return 0


def run_continuous_monitor(laravel_client: LaravelApiClient, coindcx_client: CoinDCXClient) -> None:
    """Run the high-frequency CoinDCX polling loop with slower Laravel cache refreshes."""
    run_id = datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S")
    cache = new_trade_cache()
    totals = new_run_stats()
    last_laravel_refresh_at = 0.0
    last_summary_at = time.monotonic()
    poll_count = 0

    logger.info(
        "[CFS Monitor Startup] run_id=%s mode=continuous price_poll_interval=%s laravel_refresh_interval=%s laravel_base_url=%s coindcx_market_url=%s entry_valid_hours=%s post_sl_tracking_days=%s",
        run_id,
        PRICE_POLL_INTERVAL_SECONDS,
        LARAVEL_REFRESH_INTERVAL_SECONDS,
        LARAVEL_BASE_URL,
        COINDCX_MARKET_URL,
        ENTRY_VALID_HOURS,
        POST_SL_TRACKING_DAYS,
    )

    try:
        while True:
            poll_started_at = time.monotonic()
            now = poll_started_at

            if now - last_laravel_refresh_at >= LARAVEL_REFRESH_INTERVAL_SECONDS:
                refresh_stats = new_run_stats()
                refresh_laravel_cache(laravel_client, cache, refresh_stats)
                merge_stats(totals, refresh_stats)
                last_laravel_refresh_at = time.monotonic()

            poll_count += 1
            poll_stats = new_run_stats()
            process_price_poll(
                laravel_client,
                coindcx_client,
                cache["pending_signals"],
                cache["active_trades"],
                cache["post_sl_trades"],
                poll_stats,
                mode="continuous",
                poll_number=poll_count,
            )
            merge_stats(totals, poll_stats)

            if time.monotonic() - last_summary_at >= max(60, LARAVEL_REFRESH_INTERVAL_SECONDS):
                log_run_summary(run_id, totals, prefix="[CFS Continuous Summary]")
                last_summary_at = time.monotonic()

            elapsed = time.monotonic() - poll_started_at
            sleep_seconds = max(0, PRICE_POLL_INTERVAL_SECONDS - elapsed)
            if sleep_seconds > 0:
                time.sleep(sleep_seconds)
    except KeyboardInterrupt:
        logger.info("[CFS Monitor Shutdown] run_id=%s reason=keyboard_interrupt", run_id)
    except Exception as exc:
        logger.exception("[CFS Monitor Fatal Error] run_id=%s error=%s", run_id, exc)
        error_logger.exception("[CFS Monitor Fatal Error] run_id=%s error=%s", run_id, exc)
    finally:
        log_run_summary(run_id, totals, prefix="[CFS Monitor Final Summary]")
        logger.info("[CFS Monitor Shutdown] run_id=%s completed_at=%s", run_id, timestamp())


def run_check(laravel_client: LaravelApiClient, coindcx_client: CoinDCXClient, mode: str = "once") -> None:
    run_id = datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S")
    stats = new_run_stats()
    cache = new_trade_cache()
    logger.info(
        "[CFS Monitor Run] run_id=%s started_at=%s mode=%s price_poll_interval=%s laravel_refresh_interval=%s laravel_base_url=%s coindcx_market_url=%s legacy_poll_interval=%s entry_valid_hours=%s post_sl_tracking_days=%s",
        run_id,
        timestamp(),
        mode,
        PRICE_POLL_INTERVAL_SECONDS,
        LARAVEL_REFRESH_INTERVAL_SECONDS,
        LARAVEL_BASE_URL,
        COINDCX_MARKET_URL,
        POLL_INTERVAL_SECONDS,
        ENTRY_VALID_HOURS,
        POST_SL_TRACKING_DAYS,
    )

    try:
        refresh_laravel_cache(laravel_client, cache, stats)
        process_price_poll(
            laravel_client,
            coindcx_client,
            cache["pending_signals"],
            cache["active_trades"],
            cache["post_sl_trades"],
            stats,
            mode=mode,
            poll_number=1,
        )
    except Exception as exc:
        logger.exception("[CFS Monitor Error] run_id=%s error=%s", run_id, exc)
        error_logger.exception("[CFS Monitor Error] run_id=%s error=%s", run_id, exc)
    finally:
        log_run_summary(run_id, stats, prefix="[CFS Monitor Summary]")


def new_trade_cache() -> dict:
    return {
        "pending_signals": [],
        "active_trades": [],
        "post_sl_trades": [],
        "last_refresh_success_at": None,
        "last_refresh_error": None,
    }


def refresh_laravel_cache(laravel_client: LaravelApiClient, cache: dict, stats: dict) -> bool:
    """Refresh cached Laravel trade lists, preserving previous cache on failure."""
    try:
        pending_signals = extract_items(laravel_client.get_pending_signals())
        active_trades = extract_items(laravel_client.get_active_trades())
        post_sl_trades = extract_items(laravel_client.get_post_sl_tracking_trades())
    except Exception as exc:
        stats["api_errors_count"] += 1
        cache["last_refresh_error"] = str(exc)
        logger.error(
            "[CFS Laravel Cache Refresh] status=failed error=%s cached_pending=%d cached_active=%d cached_post_sl=%d unique_symbol_count=%d",
            exc,
            len(cache["pending_signals"]),
            len(cache["active_trades"]),
            len(cache["post_sl_trades"]),
            len(collect_required_symbols(cache["pending_signals"], cache["active_trades"], cache["post_sl_trades"])),
        )
        error_logger.error("[CFS Laravel Cache Refresh] status=failed error=%s", exc)
        return False

    cache["pending_signals"] = pending_signals
    cache["active_trades"] = active_trades
    cache["post_sl_trades"] = post_sl_trades
    cache["last_refresh_success_at"] = timestamp()
    cache["last_refresh_error"] = None

    stats["pending_signals_count"] = len(pending_signals)
    stats["active_trades_count"] = len(active_trades)
    stats["post_sl_trades_count"] = len(post_sl_trades)

    symbols = collect_required_symbols(pending_signals, active_trades, post_sl_trades)
    logger.info(
        "[CFS Laravel Cache Refresh] status=success pending=%d active=%d post_sl=%d unique_symbol_count=%d symbols=%s refreshed_at=%s",
        len(pending_signals),
        len(active_trades),
        len(post_sl_trades),
        len(symbols),
        symbols,
        cache["last_refresh_success_at"],
    )
    return True


def process_price_poll(
    laravel_client: LaravelApiClient,
    coindcx_client: CoinDCXClient,
    pending_signals: list[dict],
    active_trades: list[dict],
    post_sl_trades: list[dict],
    stats: dict,
    *,
    mode: str,
    poll_number: int,
) -> None:
    symbols = collect_required_symbols(pending_signals, active_trades, post_sl_trades)
    stats["pending_signals_count"] = len(pending_signals)
    stats["active_trades_count"] = len(active_trades)
    stats["post_sl_trades_count"] = len(post_sl_trades)

    if not symbols:
        logger.info(
            "[CFS Price Poll Summary] mode=%s poll=%d status=no_symbols pending=%d active=%d post_sl=%d price_poll_interval=%s laravel_refresh_interval=%s events_sent=%d api_errors=%d coindcx_errors=%d",
            mode,
            poll_number,
            len(pending_signals),
            len(active_trades),
            len(post_sl_trades),
            PRICE_POLL_INTERVAL_SECONDS,
            LARAVEL_REFRESH_INTERVAL_SECONDS,
            stats["events_sent_count"],
            stats["api_errors_count"],
            stats["coindcx_errors_count"],
        )
        return

    prices = fetch_prices(coindcx_client, symbols, "cached pending/active/post-SL tracking", stats)
    if not prices:
        logger.error(
            "[CFS Price Poll Summary] mode=%s poll=%d status=price_fetch_failed requested_symbols=%s pending=%d active=%d post_sl=%d api_errors=%d coindcx_errors=%d",
            mode,
            poll_number,
            symbols,
            len(pending_signals),
            len(active_trades),
            len(post_sl_trades),
            stats["api_errors_count"],
            stats["coindcx_errors_count"],
        )
        return

    found_count = count_found_prices(prices, symbols)
    missing_count = len(symbols) - found_count
    stats["pending_prices_found_count"] = count_found_prices(prices, collect_symbols(pending_signals))
    stats["pending_prices_missing_count"] = len(collect_symbols(pending_signals)) - stats["pending_prices_found_count"]
    stats["active_prices_found_count"] = count_found_prices(prices, collect_trade_symbols(active_trades))
    stats["active_prices_missing_count"] = len(collect_trade_symbols(active_trades)) - stats["active_prices_found_count"]

    for signal in pending_signals:
        process_pending_signal(signal, prices, laravel_client, coindcx_client, stats)

    for trade in active_trades:
        process_active_trade(trade, prices, laravel_client, coindcx_client, stats)

    for trade in post_sl_trades:
        process_post_sl_trade(trade, prices, laravel_client, coindcx_client, stats)

    logger.info(
        "[CFS Price Poll Summary] mode=%s poll=%d status=processed requested_symbols=%s found=%d missing=%d pending=%d active=%d post_sl=%d entries_triggered=%d events_sent=%d metrics_updated=%d api_errors=%d coindcx_errors=%d missing_prices=%d",
        mode,
        poll_number,
        symbols,
        found_count,
        missing_count,
        len(pending_signals),
        len(active_trades),
        len(post_sl_trades),
        stats["entries_triggered_count"],
        stats["events_sent_count"],
        stats["metrics_updated_count"],
        stats["api_errors_count"],
        stats["coindcx_errors_count"],
        stats["missing_symbol_count"],
    )


def collect_required_symbols(pending_signals: list[dict], active_trades: list[dict], post_sl_trades: list[dict]) -> list[str]:
    symbols = []
    seen = set()

    for symbol in collect_symbols(pending_signals) + collect_trade_symbols(active_trades) + collect_trade_symbols(post_sl_trades):
        if symbol in seen:
            continue

        symbols.append(symbol)
        seen.add(symbol)

    return symbols


def merge_stats(total: dict, increment: dict) -> None:
    for key, value in increment.items():
        if isinstance(value, int):
            total[key] = total.get(key, 0) + value


def log_run_summary(run_id: str, stats: dict, prefix: str) -> None:
    logger.info(
        "%s run_id=%s pending=%d active=%d post_sl=%d entries_triggered=%d events_sent=%d metrics_updated=%d missing_prices=%d api_errors=%d coindcx_errors=%d tp_sl_events=%d gain_events=%d post_sl_events=%d completed_at=%s",
        prefix,
        run_id,
        stats["pending_signals_count"],
        stats["active_trades_count"],
        stats["post_sl_trades_count"],
        stats["entries_triggered_count"],
        stats["events_sent_count"],
        stats["metrics_updated_count"],
        stats["missing_symbol_count"],
        stats["api_errors_count"],
        stats["coindcx_errors_count"],
        stats["tp_sl_events_detected_count"],
        stats["gain_events_detected_count"],
        stats["post_sl_events_detected_count"],
        timestamp(),
    )


def process_pending_signal(signal: dict, prices: dict, laravel_client: LaravelApiClient, coindcx_client: CoinDCXClient, stats: dict) -> None:
    signal_id = signal.get("id")
    symbol = normalize_symbol(signal.get("symbol") or signal.get("pair"))

    try:
        if not signal_id:
            logger.warning("Skipping pending signal without id: %s", signal)
            return

        price_data = prices.get(symbol) if symbol and isinstance(prices, dict) else None
        current_price = safe_float(price_data.get("price")) if price_data and price_data.get("found") else None

        expired, expiry_reason = is_entry_expired(signal, ENTRY_VALID_HOURS, datetime.now(timezone.utc))
        if expired:
            payload = {
                "trade_signal_id": signal_id,
                "missed_at": timestamp(),
                "reason": expiry_reason,
                "current_price": current_price,
            }
            try:
                response = laravel_client.mark_entry_missed(payload)
                logger.info(
                    "Signal %s marked entry_missed: %s (%s)",
                    signal_id,
                    expiry_reason,
                    summarize_response(response),
                )
            except Exception as exc:
                stats["api_errors_count"] += 1
                logger.error("[CFS Monitor API Error] context=mark_entry_missed signal_id=%s error=%s", signal_id, exc)
                error_logger.error("[CFS Monitor API Error] context=mark_entry_missed signal_id=%s error=%s", signal_id, exc)
            return

        if not symbol:
            logger.warning("Skipping pending signal %s without symbol.", signal_id)
            return

        if not price_data or not price_data.get("found"):
            stats["missing_symbol_count"] += 1
            logger.warning("[CFS Monitor Missing Price] context=pending signal_id=%s symbol=%s", signal_id, symbol)
            return

        if current_price is None:
            logger.warning("Price invalid for pending signal %s symbol %s: %s", signal_id, symbol, price_data.get("price"))
            return

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

        entry_trigger_price = trigger_result["entry_trigger_price"]
        logger.info(
            "Entry triggered for signal %s %s at observed entry trigger price %s.",
            signal_id,
            symbol,
            entry_trigger_price,
        )

        payload = {
            "trade_signal_id": signal_id,
            # Simulation only: Laravel stores this observed CoinDCX trigger price
            # as simulated_trades.entry_price. No live order is placed.
            "entry_price": entry_trigger_price,
            "current_price": entry_trigger_price,
            "event_timestamp": timestamp(),
            "actual_price_move_percent": trigger_result["actual_price_move_percent"],
            "leveraged_pnl_percent": trigger_result["leveraged_pnl_percent"],
        }
        try:
            response = laravel_client.entry_triggered(payload)
            stats["entries_triggered_count"] += 1
            logger.info("Laravel entry-triggered API succeeded for signal %s: %s", signal_id, summarize_response(response))
        except Exception as exc:
            stats["api_errors_count"] += 1
            logger.error("[CFS Monitor API Error] context=entry_triggered signal_id=%s error=%s", signal_id, exc)
            error_logger.error("[CFS Monitor API Error] context=entry_triggered signal_id=%s error=%s", signal_id, exc)
            return
        simulated_trade_id = extract_simulated_trade_id(response)
        store_market_context_snapshot(
            laravel_client,
            coindcx_client,
            {
                "trade_signal_id": signal_id,
                "simulated_trade_id": simulated_trade_id,
                "symbol": symbol,
                "snapshot_type": "entry_triggered",
            },
            stats,
        )
    except Exception as exc:
        logger.exception("Failed to process pending signal %s (%s): %s", signal_id, symbol or "unknown symbol", exc)



def process_active_trade(trade: dict, prices: dict, laravel_client: LaravelApiClient, coindcx_client: CoinDCXClient, stats: dict) -> None:
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
            stats["missing_symbol_count"] += 1
            logger.warning("[CFS Monitor Missing Price] context=active trade_id=%s symbol=%s", trade_id, symbol)
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
            stats["metrics_updated_count"] += 1
            logger.info("Metrics updated for trade %s: %s", trade_id, summarize_response(response))
        except Exception as exc:
            stats["api_errors_count"] += 1
            logger.error("[CFS Monitor API Error] context=update_metrics trade_id=%s error=%s", trade_id, exc)
            error_logger.error("[CFS Monitor API Error] context=update_metrics trade_id=%s error=%s", trade_id, exc)

        tp_sl_events = detect_tp_sl_events(trade, current_price)
        gain_milestone_events = detect_gain_milestone_events(trade, current_price)
        stats["tp_sl_events_detected_count"] += len(tp_sl_events)
        stats["gain_events_detected_count"] += len(gain_milestone_events)

        for event in gain_milestone_events:
            logger.info(
                "Detected event %s for trade %s at price %s, leveraged pnl %.2f%%",
                event["event_type"],
                trade_id,
                event["event_price"],
                event["leveraged_pnl_percent"],
            )

        for event in tp_sl_events + gain_milestone_events:
            if store_trade_event(laravel_client, trade_id, event, stats):
                stats["events_sent_count"] += 1
    except Exception as exc:
        logger.exception("Failed to process active trade %s (%s): %s", trade_id, symbol or "unknown symbol", exc)



def process_post_sl_trade(trade: dict, prices: dict, laravel_client: LaravelApiClient, coindcx_client: CoinDCXClient, stats: dict) -> None:
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
            stats["missing_symbol_count"] += 1
            logger.warning("[CFS Monitor Missing Price] context=post_sl trade_id=%s symbol=%s", trade_id, symbol)
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
            stats["metrics_updated_count"] += 1
            logger.info("Post-SL metrics updated for trade %s: %s", trade_id, summarize_response(response))
        except Exception as exc:
            stats["api_errors_count"] += 1
            logger.error("[CFS Monitor API Error] context=update_metrics trade_id=%s error=%s", trade_id, exc)
            error_logger.error("[CFS Monitor API Error] context=update_metrics trade_id=%s error=%s", trade_id, exc)

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
                store_market_context_snapshot(
                    laravel_client,
                    coindcx_client,
                    {
                        "trade_signal_id": trade.get("trade_signal_id"),
                        "simulated_trade_id": trade_id,
                        "symbol": symbol,
                        "snapshot_type": "trade_closed",
                    },
                    stats,
                )
            except Exception as exc:
                stats["api_errors_count"] += 1
                logger.error("[CFS Monitor API Error] context=close_trade trade_id=%s error=%s", trade_id, exc)
                error_logger.error("[CFS Monitor API Error] context=close_trade trade_id=%s error=%s", trade_id, exc)
            return

        post_sl_events = detect_post_sl_events(trade, current_price)
        stats["post_sl_events_detected_count"] += len(post_sl_events)
        for event in post_sl_events:
            logger.info(
                "Detected post-SL event %s for trade %s at price %s, leveraged pnl %.2f%%",
                event["event_type"],
                trade_id,
                event["event_price"],
                event["leveraged_pnl_percent"],
            )
            if store_trade_event(laravel_client, trade_id, event, stats):
                stats["events_sent_count"] += 1
    except Exception as exc:
        logger.exception("Failed to process post-SL trade %s (%s): %s", trade_id, symbol or "unknown symbol", exc)

def store_trade_event(laravel_client: LaravelApiClient, trade_id, event: dict, stats: dict) -> bool:
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
        return True
    except Exception as exc:
        stats["api_errors_count"] += 1
        logger.error("[CFS Monitor API Error] context=store_event trade_id=%s event_type=%s error=%s", trade_id, event_type, exc)
        error_logger.error("[CFS Monitor API Error] context=store_event trade_id=%s event_type=%s error=%s", trade_id, event_type, exc)
        return False



def store_market_context_snapshot(
    laravel_client: LaravelApiClient,
    coindcx_client: CoinDCXClient,
    base_payload: dict,
    stats: dict,
) -> bool:
    snapshot_type = base_payload.get("snapshot_type")
    try:
        market_context = coindcx_client.get_market_context()
        payload = {
            **base_payload,
            "btc_price": market_context.get("btc_price"),
            "btc_24h_change_percent": market_context.get("btc_24h_change_percent"),
            "eth_price": market_context.get("eth_price"),
            "eth_24h_change_percent": market_context.get("eth_24h_change_percent"),
            "market_condition": market_context.get("market_condition"),
            "captured_at": market_context.get("captured_at"),
            "raw_payload": market_context.get("raw") or {},
        }
        response = laravel_client.store_market_snapshot(payload)
        logger.info(
            "Market snapshot stored for %s: %s",
            snapshot_type,
            summarize_response(response),
        )
        return True
    except Exception as exc:
        stats["api_errors_count"] += 1
        logger.error("[CFS Monitor API Error] context=market_snapshot trade_id=%s snapshot_type=%s error=%s", base_payload.get("simulated_trade_id"), snapshot_type, exc)
        error_logger.error("[CFS Monitor API Error] context=market_snapshot trade_id=%s snapshot_type=%s error=%s", base_payload.get("simulated_trade_id"), snapshot_type, exc)
        return False


def extract_simulated_trade_id(response) -> int | None:
    if not isinstance(response, dict):
        return None

    data = response.get("data")
    if not isinstance(data, dict):
        return None

    simulated_trade = data.get("simulated_trade")
    if isinstance(simulated_trade, dict):
        return simulated_trade.get("id") or simulated_trade.get("simulated_trade_id")

    return data.get("simulated_trade_id")

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


def fetch_prices(coindcx_client: CoinDCXClient, symbols: list[str], purpose: str, stats: dict) -> dict:
    prices = fetch_with_error("CoinDCX prices", lambda: coindcx_client.get_prices_for_symbols(symbols), stats, is_api=False)
    if getattr(coindcx_client, "last_fetch_failed", False):
        stats["coindcx_errors_count"] += 1

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


def fetch_with_error(label: str, callback, stats: dict | None = None, is_api: bool = True):
    try:
        return callback()
    except Exception as exc:
        if stats is not None:
            if is_api:
                stats["api_errors_count"] += 1
            else:
                stats["coindcx_errors_count"] += 1
        logger.error("Could not fetch %s: %s", label, exc)
        error_logger.error("Could not fetch %s: %s", label, exc)
        return []


def count_found_prices(prices: dict, symbols: list[str]) -> int:
    if not isinstance(prices, dict):
        return 0

    return sum(1 for symbol in symbols if prices.get(symbol, {}).get("found"))


def new_run_stats() -> dict:
    return {
        "pending_signals_count": 0,
        "pending_prices_found_count": 0,
        "pending_prices_missing_count": 0,
        "entries_triggered_count": 0,
        "active_trades_count": 0,
        "active_prices_found_count": 0,
        "active_prices_missing_count": 0,
        "metrics_updated_count": 0,
        "tp_sl_events_detected_count": 0,
        "gain_events_detected_count": 0,
        "post_sl_trades_count": 0,
        "post_sl_events_detected_count": 0,
        "api_errors_count": 0,
        "coindcx_errors_count": 0,
        "missing_symbol_count": 0,
        "events_sent_count": 0,
    }


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
    logger.info("Legacy poll interval seconds: %s", POLL_INTERVAL_SECONDS)
    logger.info("Price poll interval seconds: %s", PRICE_POLL_INTERVAL_SECONDS)
    logger.info("Laravel refresh interval seconds: %s", LARAVEL_REFRESH_INTERVAL_SECONDS)
    logger.info("Entry valid hours: %s", ENTRY_VALID_HOURS)
    logger.info("Post-SL tracking days: %s", POST_SL_TRACKING_DAYS)
    logger.info("API token configured: %s", "yes" if bool(PYTHON_API_TOKEN) else "no")
    logger.info("Config validation skipped: %s", "yes" if skip_config_check else "no")


def timestamp() -> str:
    return datetime.now(timezone.utc).isoformat()


if __name__ == "__main__":
    raise SystemExit(main())
