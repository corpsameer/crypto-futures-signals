"""Public CoinDCX market ticker client."""


class CoinDCXClient:
    """Unauthenticated client for CoinDCX ticker data."""

    def __init__(self, market_url: str, timeout: int = 15):
        self.market_url = market_url
        self.timeout = timeout

    def fetch_tickers(self):
        import requests

        response = requests.get(self.market_url, timeout=self.timeout)

        if not 200 <= response.status_code < 300:
            raise RuntimeError(
                f"CoinDCX ticker request failed: GET {self.market_url} "
                f"returned HTTP {response.status_code}. Response: {response.text or '<empty response>'}"
            )

        try:
            return response.json()
        except ValueError as exc:
            raise RuntimeError("CoinDCX ticker response was not valid JSON.") from exc

    def normalize_symbol(self, symbol: str) -> str:
        return normalize_symbol(symbol)

    def get_price_map(self) -> dict:
        tickers = self.fetch_tickers()
        price_map = {}

        for ticker in self._iter_tickers(tickers):
            symbol = self._extract_symbol(ticker)
            price = self._extract_price(ticker)

            if not symbol or price is None:
                continue

            normalized_symbol = self.normalize_symbol(symbol)
            if not normalized_symbol:
                continue

            price_map[normalized_symbol] = {
                "symbol": normalized_symbol,
                "last_price": price,
                "raw": ticker,
            }

        return price_map

    def _iter_tickers(self, tickers):
        if isinstance(tickers, list):
            return tickers

        if isinstance(tickers, dict):
            for key in ("data", "tickers", "markets"):
                value = tickers.get(key)
                if isinstance(value, list):
                    return value

            return list(tickers.values())

        return []

    def _extract_symbol(self, ticker: dict):
        if not isinstance(ticker, dict):
            return None

        for key in ("market", "symbol", "pair"):
            value = ticker.get(key)
            if value:
                return str(value)

        return None

    def _extract_price(self, ticker: dict):
        if not isinstance(ticker, dict):
            return None

        for key in ("last_price", "last", "close", "price"):
            value = ticker.get(key)
            price = self._safe_float(value)
            if price is not None:
                return price

        return None

    def _safe_float(self, value):
        if value is None or value == "":
            return None

        try:
            return float(value)
        except (TypeError, ValueError):
            return None


def normalize_symbol(symbol: str) -> str:
    return str(symbol or "").upper().replace("/", "").replace("-", "").replace("_", "").replace(" ", "")
