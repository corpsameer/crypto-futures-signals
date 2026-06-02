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


## Entry Missed / Expiry Logic

Pending signals expire when entry is not triggered within `ENTRY_VALID_HOURS` (default `24`). The Python monitor checks expiry before normal entry-trigger logic so an old pending signal cannot trigger after its allowed entry window.

Expiry age uses this priority:

1. `trade_signals.expires_at` when present.
2. `trade_signals.signal_time + ENTRY_VALID_HOURS`.
3. `trade_signals.created_at + ENTRY_VALID_HOURS`.

When a pending signal expires, Python calls Laravel's `trade-signals/mark-entry-missed` API and Laravel marks the structured trade signal as `entry_missed`. No simulated trade is created for missed entries. A `TRADE_EXPIRED` tracking event is only created when a simulated trade already exists for that signal, using idempotency on the existing simulated trade and event type.

This remains Phase 1 tracking only: no live orders are placed, no authenticated CoinDCX APIs are used, no WebSocket is added, and no Telegram integration is performed. The entry-missed status helps measure entry trigger rate, missed opportunity rate, and overall signal usefulness.

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

## Max Gain / Loss Tracking

Every active simulated trade updates its current price and max/min movement metrics through Laravel's `simulated-trades/update-metrics` API on each monitor run.

- Laravel owns the historical max/min updates so database state stays consistent across repeated monitor runs.
- `max_price_after_entry` stores the highest observed public price after entry.
- `min_price_after_entry` stores the lowest observed public price after entry.
- `max_gain_percent` stores the best leveraged P&L percentage reached after entry.
- `max_loss_percent` stores the worst leveraged P&L percentage reached after entry.
- `max_actual_gain_percent` and `max_actual_loss_percent` store the matching unleveraged price-move percentages for Phase 2 strategy analysis.
- No live trading is performed, no authenticated CoinDCX APIs are used, and no Telegram integration is performed.

## Post-SL Tracking

When an `SL_HIT` event is recorded, Laravel keeps the simulation open for post-stop-loss analysis by setting the simulated trade and linked trade signal status to `tracking_after_sl`. If `tracking_until` is empty, Laravel sets it using `SIGNAL_TRACKING_DAYS` (default 7 days).

On each monitor run, Python fetches post-SL trades from Laravel's `simulated-trades/post-sl-tracking` API and continues checking public CoinDCX REST prices until `tracking_until` expires.

Post-SL tracking records whether the original TP levels are reached after SL:

- `POST_SL_TP1_HIT`
- `POST_SL_TP2_HIT`
- `POST_SL_TP3_HIT`
- `POST_SL_TP4_HIT`
- `POST_SL_MAX_GAIN`

`POST_SL_MAX_GAIN` is sent on every post-SL monitor run with the latest recovery metrics. Laravel keeps only the best post-SL leveraged P&L for that event type and does not overwrite it with a worse value.

Every post-SL event stores:

- `event_price`
- `actual_price_move_percent`
- `leveraged_pnl_percent`
- `event_timestamp`

After the configured tracking window expires, Python closes the trade through Laravel's `simulated-trades/close` API with `status=completed` and `exit_reason=POST_SL_TRACKING_COMPLETED`. This remains simulation/tracking only: no live orders are placed, no authenticated CoinDCX APIs are used, no WebSocket is added, and no Telegram integration is performed.
