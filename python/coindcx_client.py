"""Public CoinDCX REST market ticker client."""

from __future__ import annotations

import argparse
import logging
from typing import Any

import requests

from constants import COINDCX_MARKET_URL


class CoinDCXClient:
    """Unauthenticated REST client for CoinDCX public ticker data."""

    def __init__(self, market_url: str, timeout: int = 15, logger=None):
        self.market_url = market_url
        self.timeout = timeout
        self.logger = logger or logging.getLogger(__name__)
        self.session = requests.Session()

    def normalize_symbol(self, symbol: str) -> str:
        """Return a normalized internal market symbol such as ``ICPUSDT``."""
        return normalize_symbol(symbol)

    def fetch_tickers(self) -> list:
        """Fetch ticker rows from CoinDCX, returning an empty list on failure."""
        try:
            response = self.session.get(self.market_url, timeout=self.timeout)
            response.raise_for_status()
        except requests.RequestException as exc:
            self.logger.error("CoinDCX ticker request failed for %s: %s", self.market_url, exc)
            return []

        try:
            payload = response.json()
        except ValueError as exc:
            self.logger.error("CoinDCX ticker response was not valid JSON: %s", exc)
            return []

        if isinstance(payload, list):
            return payload

        if isinstance(payload, dict):
            for key in ("data", "markets", "tickers"):
                value = payload.get(key)
                if isinstance(value, list):
                    return value

            self.logger.error(
                "CoinDCX ticker response had unexpected object format; expected a list or nested list under data, markets, or tickers."
            )
            return []

        self.logger.error(
            "CoinDCX ticker response had unexpected format %s; expected list or dict.",
            type(payload).__name__,
        )
        return []

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
                "raw": row,
            }

        self.logger.info("Parsed %d CoinDCX ticker prices.", len(price_map))
        return price_map

    def get_prices_for_symbols(self, symbols: list[str]) -> dict:
        """Return found/missing price data for the requested symbols."""
        requested_symbols = [self.normalize_symbol(symbol) for symbol in symbols]
        requested_symbols = [symbol for symbol in requested_symbols if symbol]
        price_map = self.get_price_map()
        prices = {}

        for symbol in requested_symbols:
            ticker = price_map.get(symbol)

            if ticker is None:
                self.logger.warning("Requested CoinDCX symbol was not found: %s", symbol)
                prices[symbol] = {
                    "found": False,
                    "price": None,
                    "raw": None,
                }
                continue

            prices[symbol] = {
                "found": True,
                "price": ticker["last_price"],
                "raw": ticker["raw"],
            }

        return prices

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
    logging.basicConfig(level=logging.INFO, format="%(levelname)s: %(message)s")

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
