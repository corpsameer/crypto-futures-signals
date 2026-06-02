"""Pure trade calculation and event-detection helpers."""

from datetime import datetime, timezone


ENTRY_TOLERANCE_PERCENT = 0.05


def normalize_symbol(symbol: str) -> str:
    return str(symbol or "").upper().replace("/", "").replace("-", "").replace("_", "").replace(" ", "")


def is_entry_triggered(signal: dict, current_price: float) -> bool:
    entry_min = _safe_float(signal.get("entry_min"))
    entry_max = _safe_float(signal.get("entry_max"))

    if entry_min is not None and entry_max is not None:
        lower = min(entry_min, entry_max)
        upper = max(entry_min, entry_max)
        return lower <= current_price <= upper

    entry_price = entry_min if entry_min is not None else entry_max
    if entry_price is None:
        entry_price = _safe_float(signal.get("entry_price"))

    if entry_price is None:
        return False

    tolerance = abs(entry_price) * (ENTRY_TOLERANCE_PERCENT / 100)
    return (entry_price - tolerance) <= current_price <= (entry_price + tolerance)


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


def _safe_float(value):
    if value is None or value == "":
        return None

    try:
        return float(value)
    except (TypeError, ValueError):
        return None
