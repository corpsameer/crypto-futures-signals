"""Reusable file loggers for the Crypto Futures Signal Analyzer Python monitor."""

from __future__ import annotations

import logging
from pathlib import Path

LOG_DIR = Path(__file__).resolve().parent / "logs"
LOG_FORMAT = "%(asctime)s %(levelname)s %(name)s %(message)s"
DATE_FORMAT = "%Y-%m-%d %H:%M:%S"


def _ensure_log_dir() -> None:
    LOG_DIR.mkdir(parents=True, exist_ok=True)


def _has_handler(logger: logging.Logger, handler_name: str) -> bool:
    return any(getattr(handler, "_cfs_handler_name", None) == handler_name for handler in logger.handlers)


def _file_handler(file_name: str, handler_name: str) -> logging.FileHandler:
    _ensure_log_dir()
    handler = logging.FileHandler(LOG_DIR / file_name)
    handler.setFormatter(logging.Formatter(LOG_FORMAT, datefmt=DATE_FORMAT))
    handler._cfs_handler_name = handler_name  # type: ignore[attr-defined]
    return handler


def _console_handler(handler_name: str) -> logging.StreamHandler:
    handler = logging.StreamHandler()
    handler.setFormatter(logging.Formatter(LOG_FORMAT, datefmt=DATE_FORMAT))
    handler._cfs_handler_name = handler_name  # type: ignore[attr-defined]
    return handler


def _configure_logger(name: str, file_name: str, *, console: bool = False) -> logging.Logger:
    logger = logging.getLogger(name)
    logger.setLevel(logging.INFO)
    logger.propagate = False

    file_handler_name = f"{name}:{file_name}"
    if not _has_handler(logger, file_handler_name):
        logger.addHandler(_file_handler(file_name, file_handler_name))

    if console:
        console_handler_name = f"{name}:console"
        if not _has_handler(logger, console_handler_name):
            logger.addHandler(_console_handler(console_handler_name))

    return logger


def get_monitor_logger() -> logging.Logger:
    """Return the monitor logger, writing to monitor.log and console."""
    return _configure_logger("monitor", "monitor.log", console=True)


def get_price_logger() -> logging.Logger:
    """Return the CoinDCX price fetch logger."""
    return _configure_logger("coindcx_prices", "coindcx_prices.log")


def get_error_logger() -> logging.Logger:
    """Return the shared Python error logger."""
    return _configure_logger("errors", "errors.log")
