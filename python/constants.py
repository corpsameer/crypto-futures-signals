"""Configuration constants for the Crypto Futures Signal Analyzer monitor."""

from pathlib import Path
import importlib
import importlib.util
import os

BASE_DIR = Path(__file__).resolve().parent
ENV_PATH = BASE_DIR / ".env"

if importlib.util.find_spec("dotenv") is not None:
    load_dotenv = importlib.import_module("dotenv").load_dotenv
    load_dotenv(ENV_PATH)
else:
    # Lightweight fallback so local config validation can still show clear errors
    # before dependencies are installed. Install python-dotenv for normal use.
    if ENV_PATH.exists():
        for line in ENV_PATH.read_text().splitlines():
            stripped = line.strip()
            if not stripped or stripped.startswith("#") or "=" not in stripped:
                continue

            key, value = stripped.split("=", 1)
            os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))

DEFAULT_LARAVEL_BASE_URL = "http://127.0.0.1:8000/api/cryptofuturesignals/api"
DEFAULT_PYTHON_API_TOKEN = "change_me_secure_token"
DEFAULT_COINDCX_MARKET_URL = "https://api.coindcx.com/exchange/ticker"
DEFAULT_POLL_INTERVAL_SECONDS = 15
DEFAULT_PRICE_POLL_INTERVAL_SECONDS = 2
DEFAULT_LARAVEL_REFRESH_INTERVAL_SECONDS = 20
DEFAULT_ENTRY_VALID_HOURS = 24
DEFAULT_POST_SL_TRACKING_DAYS = 7


def _get_string(name: str, default: str) -> str:
    return os.getenv(name, default).strip()


def _get_int(name: str, default: int) -> int:
    value = os.getenv(name)

    if value is None or value.strip() == "":
        return default

    try:
        return int(value)
    except ValueError:
        return default


LARAVEL_BASE_URL = _get_string("LARAVEL_BASE_URL", DEFAULT_LARAVEL_BASE_URL)
PYTHON_API_TOKEN = _get_string("PYTHON_API_TOKEN", DEFAULT_PYTHON_API_TOKEN)
COINDCX_MARKET_URL = _get_string("COINDCX_MARKET_URL", DEFAULT_COINDCX_MARKET_URL)
POLL_INTERVAL_SECONDS = _get_int("POLL_INTERVAL_SECONDS", DEFAULT_POLL_INTERVAL_SECONDS)
PRICE_POLL_INTERVAL_SECONDS = _get_int("PRICE_POLL_INTERVAL_SECONDS", DEFAULT_PRICE_POLL_INTERVAL_SECONDS)
LARAVEL_REFRESH_INTERVAL_SECONDS = _get_int("LARAVEL_REFRESH_INTERVAL_SECONDS", DEFAULT_LARAVEL_REFRESH_INTERVAL_SECONDS)
ENTRY_VALID_HOURS = _get_int("ENTRY_VALID_HOURS", DEFAULT_ENTRY_VALID_HOURS)
POST_SL_TRACKING_DAYS = _get_int("POST_SL_TRACKING_DAYS", DEFAULT_POST_SL_TRACKING_DAYS)


def require_config() -> None:
    """Validate required monitor configuration before running live checks."""
    errors = []

    if not LARAVEL_BASE_URL:
        errors.append("LARAVEL_BASE_URL must not be empty.")

    if not PYTHON_API_TOKEN:
        errors.append("PYTHON_API_TOKEN must not be empty.")
    elif PYTHON_API_TOKEN == DEFAULT_PYTHON_API_TOKEN:
        errors.append("PYTHON_API_TOKEN must be changed from the placeholder value.")

    if not COINDCX_MARKET_URL:
        errors.append("COINDCX_MARKET_URL must not be empty.")

    if errors:
        raise RuntimeError("Invalid Python monitor configuration: " + " ".join(errors))
