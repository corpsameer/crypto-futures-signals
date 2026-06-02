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

## Entry Trigger Logic

The monitor now checks pending Laravel trade signals against current public CoinDCX REST prices and simulates entry execution only.

- For `LONG` signals, the planned entry is the upper bound of the normalized entry range (`max(entry_min, entry_max)`). Entry triggers when the current price is less than or equal to that planned entry.
- For `SHORT` signals, the planned entry is the lower bound of the normalized entry range (`min(entry_min, entry_max)`). Entry triggers when the current price is greater than or equal to that planned entry.
- The MVP records simulated limit-style behavior with `entry_execution_style=simulated_limit`; it does not place live orders and does not use authenticated CoinDCX APIs.
- When a pending signal triggers, Python calls Laravel's `simulated-trades/entry-triggered` API. Laravel creates or updates the simulated trade, creates the `ENTRY_TRIGGERED` event, stores the event price/move/PnL fields, and marks the signal active.
- Python does not create a separate `ENTRY_TRIGGERED` event because Laravel handles that idempotently through the entry-triggered endpoint.

Run the local entry trigger test cases with:

```bash
python python/trade_logic.py --test
```

## TP/SL Tracking

The monitor also tracks active simulated trades created by Laravel. On each monitor run:

- Active trades are fetched from Laravel using the `simulated-trades/active` API.
- The latest unauthenticated public CoinDCX REST price is checked for each active trade symbol.
- Current metrics are updated through Laravel's `simulated-trades/update-metrics` API on every monitor run when a valid current price and entry price are available.
- TP/SL events are detected in Python and sent to Laravel's `trade-events/store` API.
- Laravel handles event idempotency, so the same `simulated_trade_id` + `event_type` can be safely recorded/updated by repeated monitor runs without Python keeping local duplicate state.
- Every TP/SL event payload includes `event_price`, `actual_price_move_percent`, `leveraged_pnl_percent`, and `event_timestamp`.
- TP events store the planned target price in metadata, while SL events store the configured stop loss in metadata. In both cases, `event_price` is the observed public CoinDCX price at detection time.
- No live orders are placed, no authenticated CoinDCX APIs are used, and no Telegram integration is performed.

## Custom Gain Milestone Tracking

Active simulated trades are also checked for custom leveraged gain milestones on each monitor run.

- Tracked leveraged gain milestones: 3%, 3.5%, 5%, and 7%.
- Created event types:
  - `GAIN_3_PERCENT`
  - `GAIN_3_5_PERCENT`
  - `GAIN_5_PERCENT`
  - `GAIN_7_PERCENT`
- Each milestone event stores:
  - `event_price`
  - `actual_price_move_percent`
  - `leveraged_pnl_percent`
  - `event_timestamp`
- Laravel handles event idempotency, so repeated monitor runs can safely update the same `simulated_trade_id` + `event_type` instead of duplicating rows.
- No live orders are placed, no authenticated CoinDCX APIs are used, and no Telegram integration is performed.
