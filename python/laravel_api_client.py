"""Small HTTP client for Laravel monitor API endpoints."""

from __future__ import annotations

import json
from urllib.parse import urljoin

import requests

from logger_config import get_error_logger, get_monitor_logger


class LaravelApiClient:
    """Client for Crypto Futures Signal Analyzer Laravel API endpoints."""

    def __init__(self, base_url: str, token: str, timeout: int = 15):
        self.base_url = base_url.rstrip("/") + "/"
        self.timeout = timeout
        self.monitor_logger = get_monitor_logger()
        self.error_logger = get_error_logger()
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
        method = "GET"
        try:
            response = requests.get(
                self._url(endpoint),
                headers=self.headers,
                timeout=self.timeout,
            )
        except requests.RequestException as exc:
            self._log_exception(method, endpoint, exc)
            raise RuntimeError(f"Laravel API request exception: {method} {endpoint}: {exc}") from exc

        return self._handle_response(response, endpoint, None)

    def _post(self, endpoint: str, payload: dict):
        method = "POST"
        try:
            response = requests.post(
                self._url(endpoint),
                headers=self.headers,
                json=payload,
                timeout=self.timeout,
            )
        except requests.RequestException as exc:
            self._log_exception(method, endpoint, exc)
            raise RuntimeError(f"Laravel API request exception: {method} {endpoint}: {exc}") from exc

        return self._handle_response(response, endpoint, payload)

    def _url(self, endpoint: str) -> str:
        return urljoin(self.base_url, endpoint.lstrip("/"))

    def _handle_response(self, response, endpoint: str, payload: dict | None):
        if not 200 <= response.status_code < 300:
            body = self._response_body_for_error(response)
            preview = self._short_preview(body)
            safe_payload = self._safe_payload_preview(payload)
            self.monitor_logger.error(
                "[CFS Laravel API Error] method=%s endpoint=%s status=%s response=%s payload=%s",
                response.request.method,
                endpoint,
                response.status_code,
                json.dumps(preview),
                safe_payload,
            )
            self.error_logger.error(
                "[CFS Laravel API Error] method=%s endpoint=%s status=%s response=%s payload=%s",
                response.request.method,
                endpoint,
                response.status_code,
                json.dumps(preview),
                safe_payload,
            )
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

    def _log_exception(self, method: str, endpoint: str, exc: Exception) -> None:
        self.monitor_logger.error(
            "[CFS Laravel API Exception] method=%s endpoint=%s error=%s",
            method,
            endpoint,
            json.dumps(str(exc)),
        )
        self.error_logger.error(
            "[CFS Laravel API Exception] method=%s endpoint=%s error=%s",
            method,
            endpoint,
            json.dumps(str(exc)),
        )

    def _response_body_for_error(self, response):
        if not response.text.strip():
            return "<empty response>"

        try:
            return response.json()
        except ValueError:
            return response.text

    def _short_preview(self, value) -> str:
        if isinstance(value, (dict, list)):
            text = json.dumps(value, default=str)
        else:
            text = str(value)

        return text[:500]

    def _safe_payload_preview(self, payload: dict | None) -> str:
        if payload is None:
            return "{}"

        safe_payload = dict(payload)
        for sensitive_key in ("token", "api_token", "authorization", "x-python-api-token", "X-PYTHON-API-TOKEN"):
            safe_payload.pop(sensitive_key, None)

        if "raw_payload" in safe_payload:
            safe_payload["raw_payload"] = "[omitted]"

        return json.dumps(safe_payload, default=str)[:1000]
