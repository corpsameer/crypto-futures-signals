"""Pure trade calculation and event-detection helpers."""

from __future__ import annotations

import argparse
from datetime import datetime, timezone


ENTRY_TOLERANCE_PERCENT = 0.05
ENTRY_EXECUTION_STYLE = "simulated_limit"
ORDER_RELIABILITY_NOTE = "MVP uses simulated limit-style entry trigger; no live order placed."
GAIN_MILESTONES = (
    (3.0, "GAIN_3_PERCENT"),
    (3.5, "GAIN_3_5_PERCENT"),
    (5.0, "GAIN_5_PERCENT"),
    (7.0, "GAIN_7_PERCENT"),
)
MILESTONE_EPSILON = 1e-9


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
    entry = safe_float(entry_price)
    price = safe_float(current_price)
    normalized_direction = _normalize_direction(direction)

    if entry is None or price is None:
        return 0.0

    if entry == 0:
        raise ValueError("entry_price must not be zero.")

    if normalized_direction == "SHORT":
        return ((entry - price) / entry) * 100

    return ((price - entry) / entry) * 100


def calculate_leveraged_pnl_percent(actual_move_percent: float, leverage: float) -> float:
    return actual_move_percent * leverage


def calculate_trade_metrics(trade: dict, current_price: float) -> dict:
    """Calculate current price, actual move, and leveraged P&L for a trade."""
    price = safe_float(current_price)
    entry_price = get_trade_entry_price(trade)
    direction = get_trade_direction(trade)
    leverage = get_trade_leverage(trade)

    if price is None:
        raise ValueError("current_price must be numeric.")

    if entry_price is None or entry_price == 0:
        raise ValueError("trade entry_price must be numeric and non-zero.")

    if direction not in {"LONG", "SHORT"}:
        raise ValueError("trade direction must be LONG or SHORT.")

    actual_move_percent = calculate_move_percent(direction, entry_price, price)
    leveraged_pnl_percent = calculate_leveraged_pnl_percent(actual_move_percent, leverage)

    return {
        "current_price": price,
        "actual_price_move_percent": actual_move_percent,
        "leveraged_pnl_percent": leveraged_pnl_percent,
    }


def detect_gain_milestone_events(trade: dict, current_price: float) -> list[dict]:
    """Detect leveraged gain milestone events for an active simulated trade."""
    price = safe_float(current_price)
    entry_price = get_trade_entry_price(trade)
    leverage = get_trade_leverage(trade)
    direction = get_trade_direction(trade)

    if price is None or entry_price is None or entry_price == 0:
        return []

    if direction not in {"LONG", "SHORT"}:
        return []

    actual_move_percent = calculate_move_percent(direction, entry_price, price)
    leveraged_pnl_percent = calculate_leveraged_pnl_percent(actual_move_percent, leverage)

    if leveraged_pnl_percent <= 0:
        return []

    events = []
    event_timestamp = datetime.now(timezone.utc).isoformat()
    for milestone_percent, event_type in GAIN_MILESTONES:
        if leveraged_pnl_percent + MILESTONE_EPSILON < milestone_percent:
            continue

        milestone_label = _format_percent(milestone_percent)
        events.append(
            {
                "event_type": event_type,
                "event_price": price,
                "actual_price_move_percent": actual_move_percent,
                "leveraged_pnl_percent": leveraged_pnl_percent,
                "event_timestamp": event_timestamp,
                "metadata": {
                    "milestone_percent": milestone_percent,
                    "current_price": price,
                    "entry_price": entry_price,
                    "source": "python_monitor",
                },
                "notes": f"Leveraged gain milestone {milestone_label}% reached",
            }
        )

    return events


def detect_tp_sl_events(trade: dict, current_price: float) -> list[dict]:
    """Detect TP/SL events for an active simulated trade at the observed price."""
    price = safe_float(current_price)
    entry_price = get_trade_entry_price(trade)
    leverage = get_trade_leverage(trade)
    direction = get_trade_direction(trade)

    if price is None or entry_price is None or entry_price == 0:
        return []

    if direction not in {"LONG", "SHORT"}:
        return []

    actual_move_percent = calculate_move_percent(direction, entry_price, price)
    leveraged_pnl_percent = calculate_leveraged_pnl_percent(actual_move_percent, leverage)

    tp_events = []
    for field_name, event_type, note in (
        ("tp1", "TP1_HIT", "TP1 hit"),
        ("tp2", "TP2_HIT", "TP2 hit"),
        ("tp3", "TP3_HIT", "TP3 hit"),
        ("tp4", "TP4_HIT", "TP4 hit"),
    ):
        target_price = safe_float(get_trade_signal_value(trade, field_name))
        if target_price is not None and _target_hit(direction, price, target_price):
            tp_events.append(
                _tp_sl_event_payload(
                    event_type=event_type,
                    current_price=price,
                    actual_move_percent=actual_move_percent,
                    leveraged_pnl_percent=leveraged_pnl_percent,
                    metadata={
                        "target_price": target_price,
                        "current_price": price,
                        "source": "python_monitor",
                    },
                    notes=note,
                )
            )

    sl_events = []
    stop_loss = safe_float(get_trade_signal_value(trade, "stop_loss"))
    if stop_loss is not None and _stop_loss_hit(direction, price, stop_loss):
        sl_events.append(
            _tp_sl_event_payload(
                event_type="SL_HIT",
                current_price=price,
                actual_move_percent=actual_move_percent,
                leveraged_pnl_percent=leveraged_pnl_percent,
                metadata={
                    "stop_loss": stop_loss,
                    "current_price": price,
                    "source": "python_monitor",
                },
                notes="SL hit",
            )
        )

    return sl_events + tp_events


def detect_events(trade: dict, current_price: float) -> list[dict]:
    """Backward-compatible event detector focused on TP/SL tracking."""
    return detect_tp_sl_events(trade, current_price)


def _tp_sl_event_payload(
    event_type: str,
    current_price: float,
    actual_move_percent: float,
    leveraged_pnl_percent: float,
    metadata: dict,
    notes: str,
) -> dict:
    return {
        "event_type": event_type,
        "event_price": current_price,
        "actual_price_move_percent": actual_move_percent,
        "leveraged_pnl_percent": leveraged_pnl_percent,
        "event_timestamp": datetime.now(timezone.utc).isoformat(),
        "metadata": metadata,
        "notes": notes,
    }


def get_trade_signal_value(trade: dict, key: str):
    if not isinstance(trade, dict):
        return None

    value = trade.get(key)
    if value not in (None, ""):
        return value

    trade_signal = trade.get("trade_signal")
    if isinstance(trade_signal, dict):
        return trade_signal.get(key)

    return None


def get_trade_symbol(trade: dict) -> str:
    if not isinstance(trade, dict):
        return ""

    return normalize_symbol(
        get_trade_signal_value(trade, "symbol")
        or get_trade_signal_value(trade, "pair")
        or trade.get("market")
    )


def get_trade_direction(trade: dict) -> str:
    return _normalize_direction(get_trade_signal_value(trade, "direction"))


def get_trade_leverage(trade: dict) -> float:
    return safe_float(get_trade_signal_value(trade, "leverage"), 1.0)


def get_trade_entry_price(trade: dict) -> float | None:
    return safe_float(get_trade_signal_value(trade, "entry_price"))


def safe_float(value, default=None):
    if value is None or value == "":
        return default

    try:
        return float(value)
    except (TypeError, ValueError):
        return default


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


def _format_percent(value: float) -> str:
    if float(value).is_integer():
        return str(int(value))

    return str(value)


def _normalize_direction(direction) -> str:
    return str(direction or "").strip().upper()


def _safe_float(value):
    return safe_float(value)


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

    failures.extend(_run_tp_sl_tests())
    failures.extend(_run_gain_milestone_tests())
    failures.extend(_run_trade_metrics_tests())

    if failures:
        print(f"Trade logic tests failed: {', '.join(failures)}")
        return 1

    print("All entry trigger, TP/SL, gain milestone, and trade metrics test cases pass.")
    return 0


def _run_tp_sl_tests() -> list[str]:
    test_cases = [
        (
            "TP/SL A) LONG TP1 hit",
            {"direction": "LONG", "entry_price": 100, "leverage": 5, "tp1": 105},
            105,
            ["TP1_HIT"],
            5,
            25,
        ),
        (
            "TP/SL B) LONG SL hit",
            {"direction": "LONG", "entry_price": 100, "leverage": 5, "stop_loss": 95},
            95,
            ["SL_HIT"],
            -5,
            -25,
        ),
        (
            "TP/SL C) SHORT TP1 hit",
            {"direction": "SHORT", "entry_price": 100, "leverage": 5, "tp1": 95},
            95,
            ["TP1_HIT"],
            5,
            25,
        ),
        (
            "TP/SL D) SHORT SL hit",
            {"direction": "SHORT", "entry_price": 100, "leverage": 5, "stop_loss": 105},
            105,
            ["SL_HIT"],
            -5,
            -25,
        ),
        (
            "TP/SL E) LONG multiple TPs hit",
            {"direction": "LONG", "entry_price": 100, "leverage": 5, "tp1": 102, "tp2": 104, "tp3": 106},
            105,
            ["TP1_HIT", "TP2_HIT"],
            5,
            25,
        ),
        (
            "TP/SL F) Missing TP values",
            {"direction": "LONG", "entry_price": "100", "leverage": "5", "tp1": None, "tp2": "", "trade_signal": {}},
            101,
            [],
            None,
            None,
        ),
        (
            "TP/SL G) Nested trade_signal TP value",
            {"entry_price": "100", "trade_signal": {"direction": "LONG", "leverage": "5", "tp1": "105"}},
            105,
            ["TP1_HIT"],
            5,
            25,
        ),
    ]

    failures = []
    for name, trade, current_price, expected_types, expected_move, expected_pnl in test_cases:
        events = detect_tp_sl_events(trade, current_price)
        actual_types = [event["event_type"] for event in events]
        passed = actual_types == expected_types

        if passed and events and expected_move is not None and expected_pnl is not None:
            passed = (
                round(events[0]["actual_price_move_percent"], 8) == round(expected_move, 8)
                and round(events[0]["leveraged_pnl_percent"], 8) == round(expected_pnl, 8)
            )

        status = "PASS" if passed else "FAIL"
        print(f"{status}: {name} expected={expected_types} actual={actual_types}")
        if not passed:
            failures.append(name)

    return failures


def _run_gain_milestone_tests() -> list[str]:
    test_cases = [
        (
            "Gain A) LONG 3% milestone",
            {"direction": "LONG", "entry_price": 100, "leverage": 5},
            100.6,
            ["GAIN_3_PERCENT"],
            0.6,
            3.0,
        ),
        (
            "Gain B) LONG 3.5% milestone",
            {"direction": "LONG", "entry_price": 100, "leverage": 5},
            100.7,
            ["GAIN_3_PERCENT", "GAIN_3_5_PERCENT"],
            0.7,
            3.5,
        ),
        (
            "Gain C) LONG 5% milestone",
            {"direction": "LONG", "entry_price": 100, "leverage": 5},
            101,
            ["GAIN_3_PERCENT", "GAIN_3_5_PERCENT", "GAIN_5_PERCENT"],
            1.0,
            5.0,
        ),
        (
            "Gain D) LONG 7% milestone",
            {"direction": "LONG", "entry_price": 100, "leverage": 5},
            101.4,
            ["GAIN_3_PERCENT", "GAIN_3_5_PERCENT", "GAIN_5_PERCENT", "GAIN_7_PERCENT"],
            1.4,
            7.0,
        ),
        (
            "Gain E) SHORT 3% milestone",
            {"direction": "SHORT", "entry_price": 100, "leverage": 5},
            99.4,
            ["GAIN_3_PERCENT"],
            0.6,
            3.0,
        ),
        (
            "Gain F) SHORT losing trade",
            {"direction": "SHORT", "entry_price": 100, "leverage": 5},
            101,
            [],
            None,
            None,
        ),
        (
            "Gain G) Missing entry price",
            {"direction": "LONG", "leverage": 5},
            101,
            [],
            None,
            None,
        ),
    ]

    failures = []
    for name, trade, current_price, expected_types, expected_move, expected_pnl in test_cases:
        events = detect_gain_milestone_events(trade, current_price)
        actual_types = [event["event_type"] for event in events]
        passed = actual_types == expected_types

        if passed and events and expected_move is not None and expected_pnl is not None:
            passed = (
                round(events[-1]["actual_price_move_percent"], 8) == round(expected_move, 8)
                and round(events[-1]["leveraged_pnl_percent"], 8) == round(expected_pnl, 8)
                and all(event["event_price"] == safe_float(current_price) for event in events)
                and all(event.get("event_timestamp") for event in events)
            )

        status = "PASS" if passed else "FAIL"
        print(f"{status}: {name} expected={expected_types} actual={actual_types}")
        if not passed:
            failures.append(name)

    return failures


def _run_trade_metrics_tests() -> list[str]:
    test_cases = [
        (
            "Metrics A) LONG gain",
            {"direction": "LONG", "entry_price": 100, "leverage": 5},
            101,
            {"current_price": 101, "actual_price_move_percent": 1.0, "leveraged_pnl_percent": 5.0},
        ),
        (
            "Metrics B) SHORT gain",
            {"direction": "SHORT", "entry_price": 100, "leverage": 5},
            99,
            {"current_price": 99, "actual_price_move_percent": 1.0, "leveraged_pnl_percent": 5.0},
        ),
        (
            "Metrics C) SHORT loss",
            {"direction": "SHORT", "entry_price": 100, "leverage": 5},
            101,
            {"current_price": 101, "actual_price_move_percent": -1.0, "leveraged_pnl_percent": -5.0},
        ),
    ]

    failures = []
    for name, trade, current_price, expected in test_cases:
        metrics = calculate_trade_metrics(trade, current_price)
        passed = all(round(metrics[key], 8) == round(value, 8) for key, value in expected.items())
        status = "PASS" if passed else "FAIL"
        print(f"{status}: {name} expected={expected} actual={metrics}")
        if not passed:
            failures.append(name)

    return failures


def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run trade logic helper checks.")
    parser.add_argument("--test", action="store_true", help="Run safe local entry trigger tests.")
    return parser.parse_args()


if __name__ == "__main__":
    args = _parse_args()
    if args.test:
        raise SystemExit(_run_entry_trigger_tests())

    print("No action requested. Use --test to run entry trigger tests.")
