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

## Testing CoinDCX Price Client

Use the safe local CLI checks to fetch public ticker prices without contacting Laravel:

```bash
python python/coindcx_client.py --test
python python/coindcx_client.py --symbols ICPUSDT CHZ/USDT OPUSDT
```

The client uses the public CoinDCX REST ticker configured by `COINDCX_MARKET_URL`. Missing symbols are logged safely and reported as `found=False` with `price=None`. This test does not perform live trading and does not use authenticated CoinDCX APIs.
