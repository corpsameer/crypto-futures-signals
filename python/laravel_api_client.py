"""Small HTTP client for Laravel monitor API endpoints."""

from urllib.parse import urljoin


class LaravelApiClient:
    """Client for Crypto Futures Signal Analyzer Laravel API endpoints."""

    def __init__(self, base_url: str, token: str, timeout: int = 15):
        self.base_url = base_url.rstrip("/") + "/"
        self.timeout = timeout
        self.headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-PYTHON-API-TOKEN": token,
        }

    def health(self):
        """Check the optional API health endpoint, returning an error-friendly dict if unavailable."""
        try:
            return self._get("/health")
        except RuntimeError as exc:
            return {"ok": False, "error": str(exc)}

    def get_pending_signals(self):
        return self._get("/trade-signals/pending")

    def get_active_trades(self):
        return self._get("/simulated-trades/active")

    def get_post_sl_tracking_trades(self):
        return self._get("/simulated-trades/post-sl-tracking")

    def entry_triggered(self, payload: dict):
        return self._post("/simulated-trades/entry-triggered", payload)

    def mark_entry_missed(self, payload: dict):
        return self._post("/trade-signals/mark-entry-missed", payload)

    def store_event(self, payload: dict):
        return self._post("/trade-events/store", payload)

    def update_metrics(self, payload: dict):
        return self._post("/simulated-trades/update-metrics", payload)

    def close_trade(self, payload: dict):
        return self._post("/simulated-trades/close", payload)

    def store_market_snapshot(self, payload: dict):
        return self._post("/market-snapshots/store", payload)

    def _get(self, endpoint: str):
        import requests

        response = requests.get(
            self._url(endpoint),
            headers=self.headers,
            timeout=self.timeout,
        )
        return self._handle_response(response)

    def _post(self, endpoint: str, payload: dict):
        import requests

        response = requests.post(
            self._url(endpoint),
            headers=self.headers,
            json=payload,
            timeout=self.timeout,
        )
        return self._handle_response(response)

    def _url(self, endpoint: str) -> str:
        return urljoin(self.base_url, endpoint.lstrip("/"))

    def _handle_response(self, response):
        if not 200 <= response.status_code < 300:
            body = self._response_body_for_error(response)
            raise RuntimeError(
                f"Laravel API request failed: {response.request.method} {response.url} "
                f"returned HTTP {response.status_code}. Response: {body}"
            )

        if not response.text.strip():
            return {}

        try:
            return response.json()
        except ValueError:
            return {"raw_text": response.text}

    def _response_body_for_error(self, response):
        if not response.text.strip():
            return "<empty response>"

        try:
            return response.json()
        except ValueError:
            return response.text
