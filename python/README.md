# Python Monitor Setup

This folder contains the initial Phase 1 Python monitor skeleton for the Crypto Futures Signal Analyzer Laravel project.

## Setup

Copy the Python environment example file:

```bash
cp python/.env.example python/.env
```

Edit `python/.env` and set `PYTHON_API_TOKEN` to match the Laravel `.env` value used by the `X-PYTHON-API-TOKEN` API header.

Install Python dependencies manually:

```bash
pip install -r python/requirements.txt
```

Run a one-time monitor check:

```bash
python python/price_monitor.py --once
```

Run a one-time monitor check with temporary config validation bypass for local dry testing before setting a real token:

```bash
python python/price_monitor.py --once --skip-config-check
```

## Phase 1 Scope

- No live trading.
- No authenticated CoinDCX API calls.
- No Telegram API integration.
- No WebSocket integration yet.
- This is only Phase 1 monitoring/data collection setup.
