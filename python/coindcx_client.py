"""Public CoinDCX REST market ticker client."""

from __future__ import annotations

import argparse
import json
from datetime import datetime, timezone
from typing import Any

import requests

from constants import COINDCX_MARKET_URL
from logger_config import get_error_logger, get_price_logger


class CoinDCXClient:
    """Unauthenticated REST client for CoinDCX public ticker data."""

    def __init__(self, market_url: str, timeout: int = 15, logger=None):
        self.market_url = market_url
        self.timeout = timeout
        self.logger = logger or get_price_logger()
        self.error_logger = get_error_logger()
        self.session = requests.Session()
        self.last_fetch_failed = False

    def normalize_symbol(self, symbol: str) -> str:
        """Return a normalized internal market symbol such as ``ICPUSDT``."""
        return normalize_symbol(symbol)

    def fetch_tickers(self) -> list:
        """Fetch ticker rows from CoinDCX, returning an empty list on failure."""
        self.last_fetch_failed = False
        self.logger.info('[CFS CoinDCX Tickers] fetch_tickers start url=%s', self.market_url)

        try:
            response = self.session.get(self.market_url, timeout=self.timeout)
            response.raise_for_status()
        except requests.RequestException as exc:
            self.last_fetch_failed = True
            self.logger.error('[CFS CoinDCX Tickers] fetch_tickers failure error="%s"', exc)
            self.error_logger.error('[CFS CoinDCX Fetch Error] url=%s error="%s"', self.market_url, exc)
            return []

        try:
            payload = response.json()
        except ValueError as exc:
            self.last_fetch_failed = True
            self.logger.error('[CFS CoinDCX Tickers] fetch_tickers failure error="invalid_json: %s"', exc)
            self.error_logger.error('[CFS CoinDCX Fetch Error] url=%s error="invalid_json: %s"', self.market_url, exc)
            return []

        rows = None
        if isinstance(payload, list):
            rows = payload
        elif isinstance(payload, dict):
            for key in ("data", "markets", "tickers"):
                value = payload.get(key)
                if isinstance(value, list):
                    rows = value
                    break

            if rows is None:
                self.last_fetch_failed = True
                message = "unexpected object format; expected a list or nested list under data, markets, or tickers"
                self.logger.error('[CFS CoinDCX Tickers] fetch_tickers failure error="%s"', message)
                self.error_logger.error('[CFS CoinDCX Fetch Error] url=%s error="%s"', self.market_url, message)
                return []
        else:
            self.last_fetch_failed = True
            message = f"unexpected format {type(payload).__name__}; expected list or dict"
            self.logger.error('[CFS CoinDCX Tickers] fetch_tickers failure error="%s"', message)
            self.error_logger.error('[CFS CoinDCX Fetch Error] url=%s error="%s"', self.market_url, message)
            return []

        self.logger.info('[CFS CoinDCX Tickers] fetch_tickers success parsed_ticker_count=%d', len(rows))
        return rows

    def extract_symbol(self, row: dict) -> str | None:
        """Extract and normalize a symbol from a ticker row."""
        if not isinstance(row, dict):
            return None

        for key in ("market", "symbol", "pair", "s"):
            value = row.get(key)
            if value is None or str(value).strip() == "":
                continue

            normalized_symbol = self.normalize_symbol(str(value))
            if normalized_symbol:
                return normalized_symbol

        return None

    def extract_price(self, row: dict) -> float | None:
        """Extract a positive float price from a ticker row."""
        if not isinstance(row, dict):
            return None

        for key in ("last_price", "last", "close", "price", "c"):
            price = self._safe_positive_float(row.get(key))
            if price is not None:
                return price

        return None

    def extract_24h_change_percent(self, row: dict) -> float | None:
        """Extract a 24h change percentage from a ticker row when CoinDCX provides one."""
        if not isinstance(row, dict):
            return None

        for key in (
            "change_24_hour",
            "change_24h",
            "price_change_percent",
            "price_change_percentage_24h",
            "percent_change_24h",
            "change",
            "pc",
        ):
            value = row.get(key)
            if value is None or value == "":
                continue

            if isinstance(value, str):
                value = value.strip().rstrip("%")

            try:
                return float(value)
            except (TypeError, ValueError):
                continue

        return None

    def get_price_map(self) -> dict:
        """Return the latest valid ticker price data keyed by normalized symbol."""
        price_map = {}

        for row in self.fetch_tickers():
            if not isinstance(row, dict):
                continue

            symbol = self.extract_symbol(row)
            price = self.extract_price(row)

            if not symbol or price is None:
                continue

            price_map[symbol] = {
                "symbol": symbol,
                "last_price": price,
                "change_24h_percent": self.extract_24h_change_percent(row),
                "raw": row,
            }

        self.logger.info("[CFS CoinDCX Tickers] parsed_valid_price_count=%d", len(price_map))
        return price_map

    def get_prices_for_symbols(self, symbols: list[str]) -> dict:
        """Return found/missing price data for the requested symbols."""
        requested_symbols = [self.normalize_symbol(symbol) for symbol in symbols]
        requested_symbols = [symbol for symbol in requested_symbols if symbol]
        self.logger.info(
            "[CFS CoinDCX Fetch] started symbols=%s",
            json.dumps(requested_symbols, separators=(",", ":")),
        )

        try:
            price_map = self.get_price_map()
        except Exception as exc:
            self.last_fetch_failed = True
            self.logger.error(
                "[CFS CoinDCX Fetch Result] status=failed error=%s requested_symbols=%s",
                json.dumps(str(exc)),
                json.dumps(requested_symbols, separators=(",", ":")),
            )
            self.error_logger.error(
                "[CFS CoinDCX Fetch Error] requested_symbols=%s error=%s",
                json.dumps(requested_symbols, separators=(",", ":")),
                json.dumps(str(exc)),
            )
            return {}

        prices = {}
        missing_symbols = []

        for symbol in requested_symbols:
            ticker = price_map.get(symbol)

            if ticker is None:
                self.logger.warning("[CFS CoinDCX Missing Price] symbol=%s", symbol)
                missing_symbols.append(symbol)
                prices[symbol] = {
                    "found": False,
                    "price": None,
                    "raw": None,
                }
                continue

            prices[symbol] = {
                "found": True,
                "price": ticker["last_price"],
                "change_24h_percent": ticker.get("change_24h_percent"),
                "raw": ticker["raw"],
            }

        price_status = {symbol: prices[symbol]["price"] for symbol in requested_symbols}
        status = "failed" if self.last_fetch_failed else "success"
        self.logger.info(
            "[CFS CoinDCX Fetch Result] status=%s requested=%d found=%d missing=%d prices=%s missing_symbols=%s",
            status,
            len(requested_symbols),
            len(requested_symbols) - len(missing_symbols),
            len(missing_symbols),
            json.dumps(price_status, separators=(",", ":")),
            json.dumps(missing_symbols, separators=(",", ":")),
        )

        return prices


    def get_market_context(self) -> dict:
        """Return simple BTC/ETH market context for Laravel market snapshots."""
        price_map = self.get_price_map()
        btc_symbol = self.normalize_symbol("BTCUSDT")
        eth_symbol = self.normalize_symbol("ETHUSDT")
        btc = price_map.get(btc_symbol, {})
        eth = price_map.get(eth_symbol, {})
        btc_change = btc.get("change_24h_percent")
        eth_change = eth.get("change_24h_percent")

        return {
            "btc_price": btc.get("last_price"),
            "btc_24h_change_percent": btc_change,
            "eth_price": eth.get("last_price"),
            "eth_24h_change_percent": eth_change,
            "market_condition": determine_market_condition(btc_change, eth_change),
            "captured_at": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
            "raw": {
                "BTCUSDT": btc.get("raw"),
                "ETHUSDT": eth.get("raw"),
            },
        }

    def get_price(self, symbol: str) -> float | None:
        """Return the latest price for one symbol, or ``None`` when unavailable."""
        normalized_symbol = self.normalize_symbol(symbol)
        if not normalized_symbol:
            return None

        price_data = self.get_prices_for_symbols([normalized_symbol]).get(normalized_symbol)
        if not price_data or not price_data.get("found"):
            return None

        return price_data.get("price")

    def _safe_positive_float(self, value: Any) -> float | None:
        if value is None or value == "":
            return None

        try:
            price = float(value)
        except (TypeError, ValueError):
            return None

        if price <= 0:
            return None

        return price


def determine_market_condition(btc_change, eth_change) -> str:
    """Classify the MVP market condition from BTC and ETH 24h change percentages."""
    if btc_change is not None and eth_change is not None:
        if btc_change > 1.5 and eth_change > 1.5:
            return "bullish"

        if btc_change < -1.5 and eth_change < -1.5:
            return "bearish"

    return "sideways"


# Backward-compatible module helper for any existing imports.
def normalize_symbol(symbol: str) -> str:
    """Normalize user/CoinDCX symbol formats to a compact uppercase form."""
    normalized = str(symbol or "").strip().upper()

    if normalized.startswith("#"):
        normalized = normalized[1:]

    if normalized.startswith("B-"):
        normalized = normalized[2:]

    for character in ("/", "-", "_", " "):
        normalized = normalized.replace(character, "")

    return normalized


def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Test the public CoinDCX REST price client.")
    parser.add_argument("--test", action="store_true", help="Run a safe local price lookup test.")
    parser.add_argument("--symbols", nargs="*", help="Symbols to request, for example ICPUSDT CHZ/USDT OPUSDT.")
    return parser.parse_args()


def _run_cli() -> int:
    args = _parse_args()
    get_price_logger()

    requested_symbols = args.symbols or ["BTCUSDT", "ETHUSDT", "ICPUSDT"]
    client = CoinDCXClient(COINDCX_MARKET_URL)
    prices = client.get_prices_for_symbols(requested_symbols)

    print("CoinDCX public REST price client test")
    print(f"Market URL: {COINDCX_MARKET_URL}")
    print(f"Requested symbols: {', '.join(requested_symbols)}")

    if not prices:
        print("No price data was returned. CoinDCX may be unreachable or the response format may have changed.")
        return 0

    for symbol, data in prices.items():
        print(f"{symbol}: found={data['found']} price={data['price']}")

    return 0


if __name__ == "__main__":
    raise SystemExit(_run_cli())
