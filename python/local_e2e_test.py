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
CURRENT_SCENARIO: str | None = None
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


def timestamp_value(value: str) -> datetime:
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


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


def latest_trade_for_signal(client: LaravelApiClient, signal_id: int) -> dict | None:
    state = api_data(call_api(f"local-test signal state {signal_id}", lambda: client.get_local_test_signal_state(signal_id)))
    trades = state.get("simulated_trades") or []
    if not trades:
        return None
    return trades[-1]


def get_trade_state(client: LaravelApiClient, simulated_trade_id: int) -> dict:
    return api_data(call_api(f"local-test trade state {simulated_trade_id}", lambda: client.get_local_test_trade_state(simulated_trade_id)))


def reload_trade(client: LaravelApiClient, simulated_trade_id: int) -> dict:
    state = get_trade_state(client, simulated_trade_id)
    trade = state.get("trade")
    assert_true(isinstance(trade, dict) and trade.get("id"), f"Local-test state missing trade for simulated_trade_id={simulated_trade_id}: {state}")
    return normalize_trade_for_logic(state)


def events_from_state(state: dict) -> list[dict]:
    return state.get("tracking_events") or []


def snapshots_from_state(state: dict) -> list[dict]:
    return state.get("market_snapshots") or []


def get_events_by_type(state: dict, event_type: str) -> list[dict]:
    return [event for event in events_from_state(state) if event.get("event_type") == event_type]


def event_exists(state: dict, event_type: str) -> bool:
    return bool(get_events_by_type(state, event_type))


def debug_event_assertion(state: dict, event_type: str) -> str:
    trade = state.get("trade") or {}
    actual_types = [event.get("event_type") for event in events_from_state(state)]
    message = (
        f"scenario={CURRENT_SCENARIO or 'unknown'} simulated_trade_id={trade.get('id')} "
        f"expected_event={event_type} actual_events={actual_types} "
        f"trade_status={trade.get('status')} current_price={trade.get('current_price')}"
    )
    logger.error("Event assertion debug: %s", message)
    print(f"DEBUG {message}")
    return message


def assert_event_exists(state: dict, event_type: str) -> dict:
    matches = get_events_by_type(state, event_type)
    if not matches:
        raise LocalE2EFailure(f"Expected event {event_type}. {debug_event_assertion(state, event_type)}")
    return matches[0]


def assert_event_fields_present(event: dict) -> None:
    for field in ("event_price", "actual_price_move_percent", "leveraged_pnl_percent", "event_timestamp"):
        assert_true(event.get(field) not in (None, ""), f"Event {event.get('event_type')} missing {field}: {event}")


def assert_no_duplicate_event(state: dict, event_type: str) -> None:
    count = len(get_events_by_type(state, event_type))
    if count != 1:
        raise LocalE2EFailure(f"Expected exactly one {event_type}; found {count}. {debug_event_assertion(state, event_type)}")


def assert_expected_events(client: LaravelApiClient, trade_id: int, event_types: list[str]) -> dict:
    state = get_trade_state(client, trade_id)
    for event_type in event_types:
        event = assert_event_exists(state, event_type)
        assert_event_fields_present(event)
        assert_no_duplicate_event(state, event_type)
    return state


def normalize_trade_for_logic(trade_state: dict) -> dict:
    trade = dict(trade_state.get("trade") or trade_state)
    trade_signal = dict(trade_state.get("trade_signal") or trade.get("trade_signal") or {})

    if not trade_signal:
        trade_signal_levels = trade.get("trade_signal_levels")
        if isinstance(trade_signal_levels, dict):
            trade_signal = dict(trade_signal_levels)

    if not trade_signal and trade.get("trade_signal_id"):
        trade_signal = {"id": trade.get("trade_signal_id")}

    for key in ("symbol", "direction", "leverage", "stop_loss", "tp1", "tp2", "tp3", "tp4"):
        if trade.get(key) in (None, "") and trade_signal.get(key) not in (None, ""):
            trade[key] = trade_signal.get(key)

    if trade_signal:
        for key in ("symbol", "direction", "leverage", "stop_loss", "tp1", "tp2", "tp3", "tp4"):
            if trade_signal.get(key) in (None, "") and trade.get(key) not in (None, ""):
                trade_signal[key] = trade.get(key)
        trade["trade_signal"] = trade_signal

    required = ["id", "symbol", "direction", "leverage", "entry_price", "stop_loss", "tp1", "tp2", "tp3", "tp4"]
    missing = [key for key in required if trade.get(key) in (None, "")]
    assert_true(not missing, f"Normalized trade missing required fields {missing}: {trade}")

    return trade


def simulated_trade_from_response(response: dict, signal_id: int) -> dict | None:
    data = response.get("data") if isinstance(response, dict) else None
    if isinstance(data, dict):
        for key in ("simulated_trade", "trade"):
            value = data.get(key)
            if isinstance(value, dict):
                nested = value.get("simulated_trade")
                if isinstance(nested, dict):
                    return nested
                if value.get("id") and (key == "simulated_trade" or value.get("trade_signal_id") == signal_id):
                    return value

    value = response.get("simulated_trade") if isinstance(response, dict) else None
    if isinstance(value, dict):
        return value

    return None


def trigger_entry_for_signal(client: LaravelApiClient, signal: dict, entry_price: float) -> dict:
    response = call_api(
        "entry-triggered",
        client.entry_triggered,
        {
            "trade_signal_id": signal["id"],
            "entry_price": entry_price,
            "current_price": entry_price,
            "event_timestamp": now_iso(),
            "actual_price_move_percent": 0,
            "leveraged_pnl_percent": 0,
        },
    )

    response_trade = simulated_trade_from_response(response, signal["id"])
    if response_trade is None:
        response_trade = latest_trade_for_signal(client, signal["id"])

    assert_true(
        isinstance(response_trade, dict) and response_trade.get("id"),
        f"Entry-triggered API did not return/create a simulated trade for trade_signal_id={signal['id']}: {response}",
    )

    state = get_trade_state(client, response_trade["id"])
    event = assert_event_exists(state, "ENTRY_TRIGGERED")
    assert_event_fields_present(event)
    assert_no_duplicate_event(state, "ENTRY_TRIGGERED")
    return normalize_trade_for_logic(state)


def store_event(client: LaravelApiClient, trade_id: int, event: dict) -> dict:
    for field in ("event_type", "event_price", "actual_price_move_percent", "leveraged_pnl_percent", "event_timestamp"):
        assert_true(event.get(field) not in (None, ""), f"Cannot store event missing {field}: {event}")

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
            "metadata": event.get("metadata") or {},
            "notes": event.get("notes"),
        },
    )
    return api_data(response)


def process_fake_price_step(client: LaravelApiClient, trade: dict, current_price: float, mode: str = "active") -> dict:
    logic_trade = normalize_trade_for_logic(trade)
    entry_price = numeric(logic_trade.get("entry_price"))
    direction = logic_trade["direction"]
    leverage = numeric(logic_trade.get("leverage"), 1.0)
    actual_move = calculate_move_percent(direction, entry_price, current_price)
    leveraged_pnl = calculate_leveraged_pnl_percent(actual_move, leverage)

    call_api(
        "update-metrics",
        client.update_metrics,
        {
            "simulated_trade_id": logic_trade["id"],
            "current_price": current_price,
            "actual_price_move_percent": actual_move,
            "leveraged_pnl_percent": leveraged_pnl,
            "price_timestamp": now_iso(),
        },
    )

    if mode == "active":
        events = detect_tp_sl_events(logic_trade, current_price) + detect_gain_milestone_events(logic_trade, current_price)
    elif mode == "post_sl":
        events = detect_post_sl_events(logic_trade, current_price)
    else:
        raise LocalE2EFailure(f"Unsupported fake price processing mode: {mode}")

    logger.info(
        "Fake price step detected events scenario=%s trade_id=%s price=%s mode=%s events=%s",
        CURRENT_SCENARIO or "unknown",
        logic_trade["id"],
        current_price,
        mode,
        [event.get("event_type") for event in events],
    )

    for event in events:
        event["simulated_trade_id"] = logic_trade["id"]
        store_event(client, logic_trade["id"], event)

    return reload_trade(client, logic_trade["id"])


def scenario_long_full_tp(client: LaravelApiClient, signal: dict) -> None:
    trade = trigger_entry_for_signal(client, signal, 100)
    for price in [100.6, 100.7, 101, 102, 103, 104]:
        trade = process_fake_price_step(client, trade, price, mode="active")

    state = assert_expected_events(client, trade["id"], [
        "ENTRY_TRIGGERED", "GAIN_3_PERCENT", "GAIN_3_5_PERCENT", "GAIN_5_PERCENT",
        "TP1_HIT", "TP2_HIT", "TP3_HIT", "TP4_HIT",
    ])
    final_trade = state["trade"]
    assert_true(numeric(final_trade.get("max_gain_percent")) >= 20, f"Expected max_gain_percent >= 20, got {final_trade.get('max_gain_percent')}")
    assert_true(numeric(final_trade.get("max_loss_percent"), 0) <= 0, "Expected max_loss_percent to remain zero or best available minimum")
    close_trade(client, final_trade, signal, 104, "TP4_HIT", "closed_tp")


def scenario_long_sl(client: LaravelApiClient, signal: dict) -> None:
    trade = trigger_entry_for_signal(client, signal, 100)
    for price in [98, 95]:
        trade = process_fake_price_step(client, trade, price, mode="active")

    state = assert_expected_events(client, trade["id"], ["ENTRY_TRIGGERED", "SL_HIT"])
    current_trade = state["trade"]
    assert_true(current_trade.get("status") == "tracking_after_sl", f"Trade status should be tracking_after_sl: {current_trade}")
    assert_true((state.get("trade_signal") or {}).get("status") == "tracking_after_sl", f"Signal status should be tracking_after_sl: {state.get('trade_signal')}")
    assert_true(current_trade.get("tracking_until") not in (None, ""), "tracking_until should be populated after SL")
    max_loss = numeric(current_trade.get("max_loss_percent"), 0)
    min_pnl = numeric(current_trade.get("min_leveraged_pnl_percent"), 0)
    assert_true(max_loss <= -25 or min_pnl <= -25, f"Expected max_loss_percent or min_leveraged_pnl_percent <= -25, got max_loss={max_loss}, min_pnl={min_pnl}")


def scenario_short_full_tp(client: LaravelApiClient, signal: dict) -> None:
    trade = trigger_entry_for_signal(client, signal, 100)
    for price in [99.4, 99.3, 99, 98, 97, 96]:
        trade = process_fake_price_step(client, trade, price, mode="active")

    state = assert_expected_events(client, trade["id"], [
        "ENTRY_TRIGGERED", "GAIN_3_PERCENT", "GAIN_3_5_PERCENT", "GAIN_5_PERCENT",
        "TP1_HIT", "TP2_HIT", "TP3_HIT", "TP4_HIT",
    ])
    tp1 = assert_event_exists(state, "TP1_HIT")
    assert_true(numeric(tp1.get("actual_price_move_percent")) > 0, "SHORT falling price should have positive actual move")
    assert_true(numeric(tp1.get("leveraged_pnl_percent")) > 0, "SHORT falling price should have positive leveraged P&L")
    close_trade(client, state["trade"], signal, 96, "TP4_HIT", "closed_tp")


def scenario_short_sl(client: LaravelApiClient, signal: dict) -> None:
    trade = trigger_entry_for_signal(client, signal, 100)
    for price in [102, 105]:
        trade = process_fake_price_step(client, trade, price, mode="active")

    state = assert_expected_events(client, trade["id"], ["ENTRY_TRIGGERED", "SL_HIT"])
    current_trade = state["trade"]
    assert_true(current_trade.get("status") == "tracking_after_sl", f"Trade status should be tracking_after_sl: {current_trade}")
    assert_true(numeric(current_trade.get("max_loss_percent"), 0) <= -25, f"Expected max_loss_percent <= -25, got {current_trade.get('max_loss_percent')}")


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
    trade = trigger_entry_for_signal(client, signal, 100)
    trade = process_fake_price_step(client, trade, 95, mode="active")
    state = assert_expected_events(client, trade["id"], ["ENTRY_TRIGGERED", "SL_HIT"])
    assert_true(state["trade"].get("status") == "tracking_after_sl", "Post-SL recovery trade should be tracking_after_sl after SL")

    trade = reload_trade(client, trade["id"])
    for price in [101, 102, 103, 104]:
        trade = process_fake_price_step(client, trade, price, mode="post_sl")

    state = assert_expected_events(client, trade["id"], [
        "POST_SL_TP1_HIT", "POST_SL_TP2_HIT", "POST_SL_TP3_HIT", "POST_SL_TP4_HIT", "POST_SL_MAX_GAIN",
    ])
    max_gain = assert_event_exists(state, "POST_SL_MAX_GAIN")
    assert_true(numeric(max_gain.get("leveraged_pnl_percent")) >= 20, f"Expected post-SL max gain >= 20, got {max_gain}")


def close_trade(client: LaravelApiClient, trade: dict, signal: dict, price: float, exit_reason: str, status: str) -> dict:
    logic_trade = normalize_trade_for_logic({"trade": trade, "trade_signal": signal})
    move = calculate_move_percent(logic_trade["direction"], numeric(logic_trade.get("entry_price")), price)
    pnl = calculate_leveraged_pnl_percent(move, numeric(logic_trade.get("leverage"), 1.0))
    response = call_api(
        "close-trade",
        client.close_trade,
        {
            "simulated_trade_id": logic_trade["id"],
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
    trade = trigger_entry_for_signal(client, signal, 100)
    trade_id = trade["id"]
    t1 = "2026-06-07T10:00:00+00:00"
    t2 = "2026-06-07T10:01:00+00:00"
    t3 = "2026-06-07T10:02:00+00:00"

    tp1_first = {
        "event_type": "TP1_HIT",
        "event_price": 101,
        "actual_price_move_percent": 1,
        "leveraged_pnl_percent": 5,
        "event_timestamp": t1,
    }
    tp1_duplicate = {
        "event_type": "TP1_HIT",
        "event_price": 102,
        "actual_price_move_percent": 2,
        "leveraged_pnl_percent": 10,
        "event_timestamp": t2,
    }
    store_event(client, trade_id, tp1_first)
    store_event(client, trade_id, tp1_duplicate)

    gain_first = {
        "event_type": "GAIN_3_5_PERCENT",
        "event_price": 100.7,
        "actual_price_move_percent": 0.7,
        "leveraged_pnl_percent": 3.5,
        "event_timestamp": t1,
    }
    gain_duplicate = {
        "event_type": "GAIN_3_5_PERCENT",
        "event_price": 101,
        "actual_price_move_percent": 1,
        "leveraged_pnl_percent": 5,
        "event_timestamp": t2,
    }
    store_event(client, trade_id, gain_first)
    store_event(client, trade_id, gain_duplicate)

    post_sl_first = {
        "event_type": "POST_SL_MAX_GAIN",
        "event_price": 101,
        "actual_price_move_percent": 1,
        "leveraged_pnl_percent": 5,
        "event_timestamp": t1,
    }
    post_sl_lower = {
        "event_type": "POST_SL_MAX_GAIN",
        "event_price": 100.8,
        "actual_price_move_percent": 0.8,
        "leveraged_pnl_percent": 4,
        "event_timestamp": t2,
    }
    post_sl_higher = {
        "event_type": "POST_SL_MAX_GAIN",
        "event_price": 101.6,
        "actual_price_move_percent": 1.6,
        "leveraged_pnl_percent": 8,
        "event_timestamp": t3,
    }
    store_event(client, trade_id, post_sl_first)
    store_event(client, trade_id, post_sl_lower)

    state = get_trade_state(client, trade_id)
    post_sl_max = assert_event_exists(state, "POST_SL_MAX_GAIN")
    assert_true(numeric(post_sl_max.get("leveraged_pnl_percent")) == 5, f"Lower POST_SL_MAX_GAIN should not replace 5%: {post_sl_max}")

    store_event(client, trade_id, post_sl_higher)
    trigger_entry_for_signal(client, signal, 102)
    state = get_trade_state(client, trade_id)

    assert_no_duplicate_event(state, "ENTRY_TRIGGERED")
    entry_event = assert_event_exists(state, "ENTRY_TRIGGERED")
    assert_true(numeric(entry_event.get("event_price")) == 100, f"ENTRY_TRIGGERED should preserve first price 100: {entry_event}")
    assert_true(numeric((state.get("trade") or {}).get("entry_price")) == 100, f"Trade entry_price should preserve first price 100: {state.get('trade')}")

    assert_no_duplicate_event(state, "TP1_HIT")
    tp1 = assert_event_exists(state, "TP1_HIT")
    assert_true(numeric(tp1.get("event_price")) == 101, f"TP1_HIT should preserve first event_price 101: {tp1}")
    assert_true(timestamp_value(tp1["event_timestamp"]) == timestamp_value(t1), f"TP1_HIT should preserve first timestamp {t1}: {tp1}")
    assert_true(numeric(tp1.get("actual_price_move_percent")) == 1, f"TP1_HIT should preserve first actual P&L: {tp1}")
    assert_true(numeric(tp1.get("leveraged_pnl_percent")) == 5, f"TP1_HIT should preserve first leveraged P&L: {tp1}")

    assert_no_duplicate_event(state, "GAIN_3_5_PERCENT")
    gain = assert_event_exists(state, "GAIN_3_5_PERCENT")
    assert_true(numeric(gain.get("event_price")) == 100.7, f"GAIN_3_5_PERCENT should preserve first event_price 100.7: {gain}")
    assert_true(timestamp_value(gain["event_timestamp"]) == timestamp_value(t1), f"GAIN_3_5_PERCENT should preserve first timestamp {t1}: {gain}")
    assert_true(numeric(gain.get("actual_price_move_percent")) == 0.7, f"GAIN_3_5_PERCENT should preserve first actual P&L: {gain}")
    assert_true(numeric(gain.get("leveraged_pnl_percent")) == 3.5, f"GAIN_3_5_PERCENT should preserve first leveraged P&L: {gain}")

    assert_no_duplicate_event(state, "POST_SL_MAX_GAIN")
    post_sl_max = assert_event_exists(state, "POST_SL_MAX_GAIN")
    assert_true(numeric(post_sl_max.get("leveraged_pnl_percent")) == 8, f"Higher POST_SL_MAX_GAIN should replace 5% with 8%: {post_sl_max}")
    assert_true(numeric(post_sl_max.get("event_price")) == 101.6, f"POST_SL_MAX_GAIN should retain the higher-gain event payload: {post_sl_max}")
    assert_true(timestamp_value(post_sl_max["event_timestamp"]) == timestamp_value(t3), f"POST_SL_MAX_GAIN should use higher-gain timestamp {t3}: {post_sl_max}")


def scenario_market_snapshot(client: LaravelApiClient, signal: dict) -> None:
    trade = trigger_entry_for_signal(client, signal, 100)
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
    global CURRENT_SCENARIO
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
        CURRENT_SCENARIO = scenario_name
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
        finally:
            CURRENT_SCENARIO = None

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
