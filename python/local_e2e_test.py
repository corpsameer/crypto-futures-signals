"""Local end-to-end scenario runner using fake prices only.

This script exercises Laravel monitor APIs with controlled local test data created by:

    php artisan cfs:test-local-e2e

It intentionally does not import or call the CoinDCX client, does not place orders, and does not
use pytest or any additional dependencies.
"""

from __future__ import annotations

import argparse
import logging
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from constants import LARAVEL_BASE_URL, PYTHON_API_TOKEN
from laravel_api_client import LaravelApiClient
from trade_logic import (
    calculate_leveraged_pnl_percent as _calculate_leveraged_pnl_percent,
    calculate_move_percent as _calculate_move_percent,
    detect_gain_milestone_events,
    detect_post_sl_events,
    detect_tp_sl_events,
)

LOG_DIR = Path(__file__).resolve().parent / "logs"
LOG_FILE = LOG_DIR / "local_e2e_test.log"
SCENARIO_BY_SYMBOL = {
    "E2ELONGUSDT": "A_LONG_FULL_TP",
    "E2ELONGSLUSDT": "B_LONG_SL",
    "E2ESHORTUSDT": "C_SHORT_FULL_TP",
    "E2ESHORTSLUSDT": "D_SHORT_SL",
    "E2EMISSEDUSDT": "E_ENTRY_MISSED",
    "E2EPOSTSLUSDT": "F_POST_SL_RECOVERY",
    "E2ECOMPLETEUSDT": "G_POST_SL_COMPLETION",
    "E2EIDEMPUSDT": "H_IDEMPOTENCY",
    "E2ESNAPSHOTUSDT": "I_MARKET_SNAPSHOT",
}


class LocalE2EFailure(AssertionError):
    """Raised when a local E2E assertion fails."""


def configure_logger() -> logging.Logger:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    logger = logging.getLogger("local_e2e_test")
    logger.setLevel(logging.INFO)
    logger.propagate = False

    if not any(getattr(handler, "_cfs_local_e2e", False) for handler in logger.handlers):
        formatter = logging.Formatter("%(asctime)s %(levelname)s %(message)s", datefmt="%Y-%m-%d %H:%M:%S")
        file_handler = logging.FileHandler(LOG_FILE)
        file_handler.setFormatter(formatter)
        file_handler._cfs_local_e2e = True  # type: ignore[attr-defined]
        logger.addHandler(file_handler)

    return logger


logger = configure_logger()


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def assert_true(condition: bool, message: str) -> None:
    if not condition:
        raise LocalE2EFailure(message)


def calculate_move_percent(direction: str, entry_price: float, current_price: float) -> float:
    return _calculate_move_percent(direction, entry_price, current_price)


def calculate_leveraged_pnl_percent(actual_move: float, leverage: float) -> float:
    return _calculate_leveraged_pnl_percent(actual_move, leverage)


def numeric(value: Any, default: float = 0.0) -> float:
    if value is None or value == "":
        return default
    return float(value)


def api_data(response: dict, key: str | None = None):
    assert_true(isinstance(response, dict) and response.get("success") is True, f"API response was not successful: {response}")
    data = response.get("data")
    if key is None:
        return data
    assert_true(isinstance(data, dict) and key in data, f"API response missing data.{key}: {response}")
    return data[key]


def call_api(label: str, func, payload: dict | None = None):
    logger.info("API call start: %s payload=%s", label, payload or {})
    response = func(payload) if payload is not None else func()
    logger.info("API call success: %s response=%s", label, response)
    return response


def signal_entry(signal: dict) -> float:
    return numeric(signal.get("entry_min") or signal.get("entry_max"))


def signal_to_trade_stub(signal: dict, trade: dict | None = None) -> dict:
    entry = numeric((trade or {}).get("entry_price"), signal_entry(signal))
    return {
        "id": (trade or {}).get("id"),
        "trade_signal_id": signal.get("id"),
        "symbol": signal.get("symbol"),
        "direction": signal.get("direction"),
        "leverage": numeric(signal.get("leverage"), 1.0),
        "entry_price": entry,
        "trade_signal_levels": {
            "tp1": signal.get("tp1"),
            "tp2": signal.get("tp2"),
            "tp3": signal.get("tp3"),
            "tp4": signal.get("tp4"),
            "stop_loss": signal.get("stop_loss"),
            "direction": signal.get("direction"),
            "leverage": signal.get("leverage"),
        },
    }


def latest_trade_for_signal(client: LaravelApiClient, signal_id: int) -> dict | None:
    state = api_data(call_api(f"local-test signal state {signal_id}", lambda: client.get_local_test_signal_state(signal_id)))
    trades = state.get("simulated_trades") or []
    return trades[-1] if trades else None


def get_trade_state(client: LaravelApiClient, simulated_trade_id: int) -> dict:
    return api_data(call_api(f"local-test trade state {simulated_trade_id}", lambda: client.get_local_test_trade_state(simulated_trade_id)))


def events_from_state(state: dict) -> list[dict]:
    return state.get("tracking_events") or []


def snapshots_from_state(state: dict) -> list[dict]:
    return state.get("market_snapshots") or []


def find_events(state: dict, event_type: str) -> list[dict]:
    return [event for event in events_from_state(state) if event.get("event_type") == event_type]


def assert_event_exists(client: LaravelApiClient, simulated_trade_id: int, event_type: str) -> dict:
    state = get_trade_state(client, simulated_trade_id)
    matches = find_events(state, event_type)
    assert_true(matches, f"Expected event {event_type} for simulated_trade_id={simulated_trade_id}")
    return matches[0]


def assert_event_fields_present(event: dict) -> None:
    for field in ("event_price", "actual_price_move_percent", "leveraged_pnl_percent", "event_timestamp"):
        assert_true(event.get(field) not in (None, ""), f"Event {event.get('event_type')} missing {field}: {event}")


def assert_no_duplicate_event(client: LaravelApiClient, simulated_trade_id: int, event_type: str) -> None:
    state = get_trade_state(client, simulated_trade_id)
    count = len(find_events(state, event_type))
    assert_true(count == 1, f"Expected exactly one {event_type}; found {count} for simulated_trade_id={simulated_trade_id}")


def entry_trigger(client: LaravelApiClient, signal: dict, price: float) -> dict:
    entry = signal_entry(signal)
    move = calculate_move_percent(signal["direction"], entry, price)
    pnl = calculate_leveraged_pnl_percent(move, numeric(signal.get("leverage"), 1.0))
    response = call_api(
        "entry-triggered",
        client.entry_triggered,
        {
            "trade_signal_id": signal["id"],
            "entry_price": price,
            "current_price": price,
            "event_timestamp": now_iso(),
            "actual_price_move_percent": move,
            "leveraged_pnl_percent": pnl,
        },
    )
    trade = api_data(response, "simulated_trade")
    return trade


def ensure_trade(client: LaravelApiClient, signal: dict, price: float = 100.0) -> dict:
    existing = latest_trade_for_signal(client, signal["id"])
    if existing:
        return existing
    return entry_trigger(client, signal, price)


def update_metrics(client: LaravelApiClient, trade: dict, signal: dict, price: float) -> dict:
    move = calculate_move_percent(signal["direction"], numeric(trade.get("entry_price"), signal_entry(signal)), price)
    pnl = calculate_leveraged_pnl_percent(move, numeric(signal.get("leverage"), 1.0))
    response = call_api(
        "update-metrics",
        client.update_metrics,
        {
            "simulated_trade_id": trade["id"],
            "current_price": price,
            "actual_price_move_percent": move,
            "leveraged_pnl_percent": pnl,
            "price_timestamp": now_iso(),
        },
    )
    return api_data(response)


def store_event(client: LaravelApiClient, trade_id: int, event: dict) -> dict:
    response = call_api(
        f"store-event {event['event_type']}",
        client.store_event,
        {
            "simulated_trade_id": trade_id,
            "event_type": event["event_type"],
            "event_price": event["event_price"],
            "actual_price_move_percent": event["actual_price_move_percent"],
            "leveraged_pnl_percent": event["leveraged_pnl_percent"],
            "event_timestamp": event["event_timestamp"],
            "metadata": event.get("metadata") or {"source": "local_e2e_test"},
            "notes": event.get("notes") or "Local E2E event",
        },
    )
    return api_data(response)


def emit_events_for_price(client: LaravelApiClient, trade: dict, signal: dict, price: float, *, post_sl: bool = False) -> dict:
    trade_stub = signal_to_trade_stub(signal, trade)
    update_metrics(client, trade, signal, price)
    detectors = [detect_post_sl_events] if post_sl else [detect_gain_milestone_events, detect_tp_sl_events]
    for detector in detectors:
        for event in detector(trade_stub, price):
            store_event(client, trade["id"], event)
    return get_trade_state(client, trade["id"])


def assert_expected_events(client: LaravelApiClient, trade_id: int, event_types: list[str]) -> dict:
    state = get_trade_state(client, trade_id)
    for event_type in event_types:
        event = assert_event_exists(client, trade_id, event_type)
        assert_event_fields_present(event)
        assert_no_duplicate_event(client, trade_id, event_type)
    return state


def scenario_long_full_tp(client: LaravelApiClient, signal: dict) -> None:
    trade = ensure_trade(client, signal, 100)
    for price in [100.6, 100.7, 101, 102, 103, 104]:
        emit_events_for_price(client, trade, signal, price)
    state = assert_expected_events(client, trade["id"], [
        "ENTRY_TRIGGERED", "GAIN_3_PERCENT", "GAIN_3_5_PERCENT", "GAIN_5_PERCENT", "GAIN_7_PERCENT",
        "TP1_HIT", "TP2_HIT", "TP3_HIT", "TP4_HIT",
    ])
    final_trade = state["trade"]
    assert_true(numeric(final_trade.get("max_gain_percent")) >= 20, f"Expected max_gain_percent >= 20, got {final_trade.get('max_gain_percent')}")
    assert_true(numeric(final_trade.get("max_loss_percent"), 0) <= 0, "Expected max_loss_percent to remain zero or best available minimum")
    close_trade(client, final_trade, signal, 104, "TP4_HIT", "closed_tp")


def scenario_long_sl(client: LaravelApiClient, signal: dict) -> None:
    trade = ensure_trade(client, signal, 100)
    for price in [98, 95]:
        emit_events_for_price(client, trade, signal, price)
    state = assert_expected_events(client, trade["id"], ["ENTRY_TRIGGERED", "SL_HIT"])
    assert_true(state["trade"].get("status") == "tracking_after_sl", f"Trade status should be tracking_after_sl: {state['trade']}")
    assert_true((state.get("trade_signal") or {}).get("status") == "tracking_after_sl", f"Signal status should be tracking_after_sl: {state.get('trade_signal')}")
    assert_true(state["trade"].get("tracking_until") not in (None, ""), "tracking_until should be populated after SL")
    assert_true(numeric(state["trade"].get("max_loss_percent")) <= -25, f"Expected max_loss_percent <= -25, got {state['trade'].get('max_loss_percent')}")


def scenario_short_full_tp(client: LaravelApiClient, signal: dict) -> None:
    trade = ensure_trade(client, signal, 100)
    for price in [99.4, 99.3, 99, 98, 97, 96]:
        emit_events_for_price(client, trade, signal, price)
    state = assert_expected_events(client, trade["id"], [
        "ENTRY_TRIGGERED", "GAIN_3_PERCENT", "GAIN_3_5_PERCENT", "GAIN_5_PERCENT", "GAIN_7_PERCENT",
        "TP1_HIT", "TP2_HIT", "TP3_HIT", "TP4_HIT",
    ])
    tp1 = assert_event_exists(client, trade["id"], "TP1_HIT")
    assert_true(numeric(tp1.get("actual_price_move_percent")) > 0, "SHORT falling price should have positive actual move")
    assert_true(numeric(tp1.get("leveraged_pnl_percent")) > 0, "SHORT falling price should have positive leveraged P&L")
    close_trade(client, state["trade"], signal, 96, "TP4_HIT", "closed_tp")


def scenario_short_sl(client: LaravelApiClient, signal: dict) -> None:
    trade = ensure_trade(client, signal, 100)
    for price in [102, 105]:
        emit_events_for_price(client, trade, signal, price)
    state = assert_expected_events(client, trade["id"], ["ENTRY_TRIGGERED", "SL_HIT"])
    assert_true(state["trade"].get("status") == "tracking_after_sl", f"Trade status should be tracking_after_sl: {state['trade']}")
    assert_true(numeric(state["trade"].get("max_loss_percent")) <= -25, f"Expected max_loss_percent <= -25, got {state['trade'].get('max_loss_percent')}")


def scenario_entry_missed(client: LaravelApiClient, signal: dict) -> None:
    response = call_api(
        "mark-entry-missed",
        client.mark_entry_missed,
        {
            "trade_signal_id": signal["id"],
            "missed_at": now_iso(),
            "reason": "Local E2E forced entry-missed scenario",
            "current_price": 110,
        },
    )
    data = api_data(response)
    trade_signal = data.get("trade_signal") or {}
    assert_true(trade_signal.get("status") == "entry_missed", f"Signal should be entry_missed: {trade_signal}")
    state = api_data(call_api("local-test missed signal state", lambda: client.get_local_test_signal_state(signal["id"])))
    assert_true(len(state.get("simulated_trades") or []) == 0, "Entry-missed scenario should not create a simulated trade")
    assert_true(not any(event.get("event_type") == "ENTRY_TRIGGERED" for event in state.get("tracking_events") or []), "Entry-missed scenario should not have ENTRY_TRIGGERED")


def scenario_post_sl_recovery(client: LaravelApiClient, signal: dict) -> None:
    trade = ensure_trade(client, signal, 100)
    emit_events_for_price(client, trade, signal, 95)
    state = assert_expected_events(client, trade["id"], ["ENTRY_TRIGGERED", "SL_HIT"])
    assert_true(state["trade"].get("status") == "tracking_after_sl", "Post-SL recovery trade should be tracking_after_sl after SL")
    for price in [101, 102, 103, 104]:
        emit_events_for_price(client, trade, signal, price, post_sl=True)
    state = assert_expected_events(client, trade["id"], [
        "POST_SL_TP1_HIT", "POST_SL_TP2_HIT", "POST_SL_TP3_HIT", "POST_SL_TP4_HIT", "POST_SL_MAX_GAIN",
    ])
    max_gain = assert_event_exists(client, trade["id"], "POST_SL_MAX_GAIN")
    assert_true(numeric(max_gain.get("leveraged_pnl_percent")) >= 20, f"Expected post-SL max gain >= 20, got {max_gain}")


def close_trade(client: LaravelApiClient, trade: dict, signal: dict, price: float, exit_reason: str, status: str) -> dict:
    move = calculate_move_percent(signal["direction"], numeric(trade.get("entry_price"), signal_entry(signal)), price)
    pnl = calculate_leveraged_pnl_percent(move, numeric(signal.get("leverage"), 1.0))
    response = call_api(
        "close-trade",
        client.close_trade,
        {
            "simulated_trade_id": trade["id"],
            "exit_price": price,
            "exit_reason": exit_reason,
            "status": status,
            "actual_price_move_percent": move,
            "leveraged_pnl_percent": pnl,
            "closed_at": now_iso(),
            "notes": "Local E2E close",
        },
    )
    return api_data(response)


def scenario_post_sl_completion(client: LaravelApiClient, signal: dict) -> None:
    trade = latest_trade_for_signal(client, signal["id"])
    assert_true(trade is not None, "Scenario G should have a pre-created post-SL tracking trade")
    result = close_trade(client, trade, signal, 101, "POST_SL_TRACKING_COMPLETED", "completed")
    closed = result.get("simulated_trade") or {}
    assert_true(closed.get("status") == "completed", f"Trade should be completed: {closed}")
    state = assert_expected_events(client, trade["id"], ["TRADE_CLOSED"])
    assert_true((state.get("trade_signal") or {}).get("status") == "completed", f"Signal should be completed: {state.get('trade_signal')}")


def scenario_idempotency(client: LaravelApiClient, signal: dict) -> None:
    trade = ensure_trade(client, signal, 100)
    entry_trigger(client, signal, 100)
    trade_stub = signal_to_trade_stub(signal, trade)
    tp1_event = detect_tp_sl_events(trade_stub, 101)[0]
    gain_event = [event for event in detect_gain_milestone_events(trade_stub, 100.7) if event["event_type"] == "GAIN_3_5_PERCENT"][0]
    for event in [tp1_event, tp1_event, gain_event, gain_event]:
        store_event(client, trade["id"], event)
    for event_type in ["ENTRY_TRIGGERED", "TP1_HIT", "GAIN_3_5_PERCENT"]:
        assert_no_duplicate_event(client, trade["id"], event_type)


def scenario_market_snapshot(client: LaravelApiClient, signal: dict) -> None:
    trade = ensure_trade(client, signal, 100)
    response = call_api(
        "market-snapshot",
        client.store_market_snapshot,
        {
            "trade_signal_id": signal["id"],
            "simulated_trade_id": trade["id"],
            "symbol": signal["symbol"],
            "snapshot_type": "entry_triggered",
            "btc_price": 65000,
            "btc_24h_change_percent": 2,
            "eth_price": 3500,
            "eth_24h_change_percent": 1.8,
            "market_condition": "bullish",
            "captured_at": now_iso(),
            "raw_payload": {"source": "local_e2e_test", "uses_fake_prices": True},
        },
    )
    snapshot = api_data(response)
    assert_true(snapshot.get("market_condition") == "bullish", f"Snapshot should be bullish: {snapshot}")
    assert_true(snapshot.get("captured_at") not in (None, ""), f"Snapshot captured_at should be present: {snapshot}")
    state = get_trade_state(client, trade["id"])
    snapshots = [item for item in snapshots_from_state(state) if item.get("snapshot_type") == "entry_triggered"]
    assert_true(snapshots, "Expected entry_triggered market snapshot in local-test trade state")


SCENARIO_RUNNERS = {
    "A_LONG_FULL_TP": scenario_long_full_tp,
    "B_LONG_SL": scenario_long_sl,
    "C_SHORT_FULL_TP": scenario_short_full_tp,
    "D_SHORT_SL": scenario_short_sl,
    "E_ENTRY_MISSED": scenario_entry_missed,
    "F_POST_SL_RECOVERY": scenario_post_sl_recovery,
    "G_POST_SL_COMPLETION": scenario_post_sl_completion,
    "H_IDEMPOTENCY": scenario_idempotency,
    "I_MARKET_SNAPSHOT": scenario_market_snapshot,
}


def fetch_signals(client: LaravelApiClient, batch: str) -> tuple[str | None, dict[str, dict]]:
    response = call_api(f"local-test signals batch={batch}", lambda: client.get_local_test_signals(batch))
    data = api_data(response)
    resolved_batch = data.get("batch") if isinstance(data, dict) else None
    raw_signals = data.get("signals") if isinstance(data, dict) else []
    signals = {signal.get("symbol"): signal for signal in raw_signals if signal.get("symbol") in SCENARIO_BY_SYMBOL}
    return resolved_batch, signals


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Run local Crypto Futures Signal Analyzer E2E scenarios with fake prices.")
    parser.add_argument("--batch", default="latest", help="Batch marker to run, or latest (default).")
    args = parser.parse_args(argv)

    print("Crypto Futures Signal Analyzer local E2E test runner")
    print("Uses fake prices only. Does not call CoinDCX and does not place live trades.")
    logger.info("Local E2E run start batch=%s base_url=%s", args.batch, LARAVEL_BASE_URL)

    client = LaravelApiClient(LARAVEL_BASE_URL, PYTHON_API_TOKEN)
    health = client.health()
    assert_true(isinstance(health, dict) and health.get("success") is True, f"Laravel API health failed: {health}")

    resolved_batch, signals = fetch_signals(client, args.batch)
    missing = [symbol for symbol in SCENARIO_BY_SYMBOL if symbol not in signals]
    if missing:
        message = f"Missing local E2E test signals: {missing}. Run php artisan cfs:test-local-e2e first."
        print("FAIL", message)
        logger.error(message)
        return 1

    print(f"Batch: {resolved_batch or args.batch}")
    results: list[tuple[str, bool, str]] = []
    for symbol, scenario_name in SCENARIO_BY_SYMBOL.items():
        logger.info("Scenario start: %s symbol=%s", scenario_name, symbol)
        try:
            SCENARIO_RUNNERS[scenario_name](client, signals[symbol])
            results.append((scenario_name, True, ""))
            print(f"PASS {scenario_name} ({symbol})")
            logger.info("Scenario pass: %s", scenario_name)
        except Exception as exc:  # noqa: BLE001 - local CLI needs full summary instead of first failure exit.
            reason = str(exc)
            results.append((scenario_name, False, reason))
            print(f"FAIL {scenario_name} ({symbol}): {reason}")
            logger.exception("Scenario fail: %s reason=%s", scenario_name, reason)

    passed = sum(1 for _, ok, _ in results if ok)
    failed = len(results) - passed
    print("\nFinal summary")
    print(f"total scenarios: {len(results)}")
    print(f"passed: {passed}")
    print(f"failed: {failed}")
    if failed:
        print("failed reasons:")
        for name, ok, reason in results:
            if not ok:
                print(f"- {name}: {reason}")

    logger.info("Local E2E summary total=%s passed=%s failed=%s", len(results), passed, failed)
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
