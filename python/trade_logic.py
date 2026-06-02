"""Pure trade calculation and event-detection helpers."""

from __future__ import annotations

import argparse
from datetime import datetime, timezone


ENTRY_TOLERANCE_PERCENT = 0.05
ENTRY_EXECUTION_STYLE = "simulated_limit"
ORDER_RELIABILITY_NOTE = "MVP uses simulated limit-style entry trigger; no live order placed."


def normalize_symbol(symbol: str) -> str:
    normalized = str(symbol or "").strip().upper()

    if normalized.startswith("#"):
        normalized = normalized[1:]

    if normalized.startswith("B-"):
        normalized = normalized[2:]

    return normalized.replace("/", "").replace("-", "").replace("_", "").replace(" ", "")


def get_entry_bounds(signal: dict) -> tuple[float | None, float | None]:
    """Return normalized lower/upper entry prices from a signal."""
    entry_min = _safe_float(signal.get("entry_min"))
    entry_max = _safe_float(signal.get("entry_max"))

    if entry_min is None and entry_max is None:
        entry_price = _safe_float(signal.get("entry_price"))
        if entry_price is None:
            return None, None
        return entry_price, entry_price

    if entry_min is None:
        return entry_max, entry_max

    if entry_max is None:
        return entry_min, entry_min

    return min(entry_min, entry_max), max(entry_min, entry_max)


def get_planned_entry_price(signal: dict) -> float | None:
    """Return preferred planned entry price for the signal direction."""
    direction = _normalize_direction(signal.get("direction"))
    lower_entry, upper_entry = get_entry_bounds(signal)

    if direction == "LONG":
        return upper_entry

    if direction == "SHORT":
        return lower_entry

    return None


def should_trigger_entry(signal: dict, current_price: float) -> dict:
    """Decide whether a pending signal has reached its simulated limit-style entry."""
    direction = _normalize_direction(signal.get("direction"))
    price = _safe_float(current_price)
    lower_entry, upper_entry = get_entry_bounds(signal)
    leverage = _safe_float(signal.get("leverage")) or 1.0

    result = {
        "triggered": False,
        "reason": "",
        "direction": direction,
        "planned_entry_price": None,
        "fill_price": None,
        "actual_price_move_percent": 0.0,
        "leveraged_pnl_percent": 0.0,
        "simulated_order_type": "limit",
        "entry_execution_style": ENTRY_EXECUTION_STYLE,
        "order_reliability_note": ORDER_RELIABILITY_NOTE,
    }

    if direction not in {"LONG", "SHORT"}:
        result["reason"] = "Missing or unsupported signal direction; expected LONG or SHORT."
        return result

    if price is None:
        result["reason"] = "Missing or invalid current price."
        return result

    if lower_entry is None or upper_entry is None:
        result["reason"] = "Missing valid entry_min, entry_max, or entry_price."
        return result

    planned_entry_price = upper_entry if direction == "LONG" else lower_entry
    result["planned_entry_price"] = planned_entry_price

    if planned_entry_price is None or planned_entry_price == 0:
        result["reason"] = "Missing or invalid planned entry price."
        return result

    if direction == "LONG":
        if price > upper_entry:
            result["reason"] = "waiting_for_limit_pullback: current price is above the LONG upper entry price."
            return result

        fill_price = min(price, upper_entry)
        result["triggered"] = True
        result["reason"] = "entry_triggered: current price is at or below the LONG upper entry price."
    else:
        if price < lower_entry:
            result["reason"] = "waiting_for_limit_retest: current price is below the SHORT lower entry price."
            return result

        fill_price = max(price, lower_entry)
        result["triggered"] = True
        result["reason"] = "entry_triggered: current price is at or above the SHORT lower entry price."

    actual_move_percent = calculate_entry_slippage_percent(direction, planned_entry_price, fill_price)
    leveraged_pnl_percent = calculate_leveraged_pnl_percent(actual_move_percent, leverage)

    result.update(
        {
            "fill_price": fill_price,
            "actual_price_move_percent": actual_move_percent,
            "leveraged_pnl_percent": leveraged_pnl_percent,
        }
    )

    return result


def calculate_entry_slippage_percent(direction: str, planned_entry_price: float, fill_price: float) -> float:
    """Calculate the percent move between planned entry and simulated fill price."""
    planned = _safe_float(planned_entry_price)
    fill = _safe_float(fill_price)
    normalized_direction = _normalize_direction(direction)

    if planned is None or fill is None:
        return 0.0

    if planned == 0:
        raise ValueError("planned_entry_price must not be zero.")

    if fill == planned:
        return 0.0

    if normalized_direction == "SHORT":
        return ((planned - fill) / planned) * 100

    return ((fill - planned) / planned) * 100


def is_entry_triggered(signal: dict, current_price: float) -> bool:
    return bool(should_trigger_entry(signal, current_price).get("triggered"))


def calculate_move_percent(direction: str, entry_price: float, current_price: float) -> float:
    if entry_price == 0:
        raise ValueError("entry_price must not be zero.")

    normalized_direction = str(direction or "").upper()

    if normalized_direction == "SHORT":
        return ((entry_price - current_price) / entry_price) * 100

    return ((current_price - entry_price) / entry_price) * 100


def calculate_leveraged_pnl_percent(actual_move_percent: float, leverage: float) -> float:
    return actual_move_percent * leverage


def detect_events(trade: dict, current_price: float) -> list[dict]:
    entry_price = _safe_float(trade.get("entry_price"))
    leverage = _safe_float(trade.get("leverage")) or 1.0
    direction = str(trade.get("direction") or "LONG").upper()

    if entry_price is None or entry_price == 0:
        return []

    actual_move_percent = calculate_move_percent(direction, entry_price, current_price)
    leveraged_pnl_percent = calculate_leveraged_pnl_percent(actual_move_percent, leverage)
    events = []

    for threshold, event_type in (
        (3, "GAIN_3_PERCENT"),
        (3.5, "GAIN_3_5_PERCENT"),
        (5, "GAIN_5_PERCENT"),
        (7, "GAIN_7_PERCENT"),
    ):
        if leveraged_pnl_percent >= threshold:
            events.append(_event_payload(trade, event_type, current_price, actual_move_percent, leveraged_pnl_percent))

    for field_name, event_type in (
        ("tp1", "TP1_HIT"),
        ("tp2", "TP2_HIT"),
        ("tp3", "TP3_HIT"),
        ("tp4", "TP4_HIT"),
    ):
        target_price = _safe_float(trade.get(field_name))
        if target_price is not None and _target_hit(direction, current_price, target_price):
            events.append(_event_payload(trade, event_type, current_price, actual_move_percent, leveraged_pnl_percent))

    stop_loss = _safe_float(trade.get("stop_loss"))
    if stop_loss is not None and _stop_loss_hit(direction, current_price, stop_loss):
        events.append(_event_payload(trade, "SL_HIT", current_price, actual_move_percent, leveraged_pnl_percent))

    return events


def _event_payload(
    trade: dict,
    event_type: str,
    current_price: float,
    actual_move_percent: float,
    leveraged_pnl_percent: float,
) -> dict:
    payload = {
        "event_type": event_type,
        "event_price": current_price,
        "actual_price_move_percent": actual_move_percent,
        "leveraged_pnl_percent": leveraged_pnl_percent,
        "event_timestamp": datetime.now(timezone.utc).isoformat(),
        "metadata": {},
    }

    trade_id = trade.get("simulated_trade_id") or trade.get("id")
    if trade_id is not None:
        payload["simulated_trade_id"] = trade_id

    return payload


def _target_hit(direction: str, current_price: float, target_price: float) -> bool:
    if direction == "SHORT":
        return current_price <= target_price

    return current_price >= target_price


def _stop_loss_hit(direction: str, current_price: float, stop_loss: float) -> bool:
    if direction == "SHORT":
        return current_price >= stop_loss

    return current_price <= stop_loss


def _normalize_direction(direction) -> str:
    return str(direction or "").strip().upper()


def _safe_float(value):
    if value is None or value == "":
        return None

    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def _run_entry_trigger_tests() -> int:
    test_cases = [
        (
            "A) LONG ICP exact entry",
            {"symbol": "ICPUSDT", "direction": "LONG", "entry_min": 2.6926, "entry_max": 2.6926, "leverage": 10},
            2.6926,
            True,
        ),
        (
            "B) LONG waiting for pullback",
            {"direction": "LONG", "entry_min": 2.69, "entry_max": 2.70, "leverage": 10},
            2.71,
            False,
        ),
        (
            "C) LONG below entry",
            {"direction": "LONG", "entry_min": 2.69, "entry_max": 2.70, "leverage": 10},
            2.68,
            True,
        ),
        (
            "D) SHORT waiting for retest",
            {"direction": "SHORT", "entry_min": 100, "entry_max": 101, "leverage": 5},
            99,
            False,
        ),
        (
            "E) SHORT at lower entry",
            {"direction": "SHORT", "entry_min": 100, "entry_max": 101, "leverage": 5},
            100,
            True,
        ),
        (
            "F) SHORT above entry",
            {"direction": "SHORT", "entry_min": 100, "entry_max": 101, "leverage": 5},
            102,
            True,
        ),
    ]

    failures = []
    for name, signal, current_price, expected in test_cases:
        result = should_trigger_entry(signal, current_price)
        actual = result["triggered"]
        status = "PASS" if actual is expected else "FAIL"
        print(f"{status}: {name} expected={expected} actual={actual} reason={result['reason']}")
        if actual is not expected:
            failures.append(name)

    if failures:
        print(f"Entry trigger tests failed: {', '.join(failures)}")
        return 1

    print("All entry trigger test cases pass.")
    return 0


def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run trade logic helper checks.")
    parser.add_argument("--test", action="store_true", help="Run safe local entry trigger tests.")
    return parser.parse_args()


if __name__ == "__main__":
    args = _parse_args()
    if args.test:
        raise SystemExit(_run_entry_trigger_tests())

    print("No action requested. Use --test to run entry trigger tests.")
