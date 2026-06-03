@extends('layouts.app')

@section('title', 'System Logs | Crypto Futures Signal Analyzer')

@section('content')
    <div class="mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1 class="h3 mb-1">System Logs</h1>
                <p class="text-muted mb-0">View file-based system logs for parser, Python monitor, CoinDCX price fetches, and Laravel API activity.</p>
            </div>
            <a href="{{ route('cryptofuturesignals.dashboard') }}" class="btn btn-outline-secondary btn-sm">Dashboard</a>
        </div>
    </div>

    <div class="card metric-card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('cryptofuturesignals.logs.index') }}" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="log_type" class="form-label fw-semibold">Log Type</label>
                    <select id="log_type" name="log_type" class="form-select">
                        @foreach ($availableLogs as $logType => $log)
                            <option value="{{ $logType }}" @selected($selectedLogType === $logType)>{{ $log['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-6 col-md-2">
                    <label for="lines" class="form-label fw-semibold">Lines</label>
                    <select id="lines" name="lines" class="form-select">
                        @foreach ([50, 100, 200, 500, 1000] as $lineOption)
                            <option value="{{ $lineOption }}" @selected($lines === $lineOption)>{{ $lineOption }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label for="search" class="form-label fw-semibold">Search</label>
                    <input
                        id="search"
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        class="form-control"
                        placeholder="Search symbol, error, run_id, event type..."
                    >
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">View Logs</button>
                    <a href="{{ route('cryptofuturesignals.logs.index') }}" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>

            <div class="d-flex flex-wrap gap-2 mt-4">
                <a href="{{ route('cryptofuturesignals.logs.index', ['log_type' => 'coindcx_prices', 'lines' => $lines]) }}" class="btn btn-sm {{ $selectedLogType === 'coindcx_prices' ? 'btn-dark' : 'btn-outline-dark' }}">CoinDCX Price Fetch Logs</a>
                <a href="{{ route('cryptofuturesignals.logs.index', ['log_type' => 'monitor', 'lines' => $lines]) }}" class="btn btn-sm {{ $selectedLogType === 'monitor' ? 'btn-dark' : 'btn-outline-dark' }}">Monitor Logs</a>
                <a href="{{ route('cryptofuturesignals.logs.index', ['log_type' => 'python_errors', 'lines' => $lines]) }}" class="btn btn-sm {{ $selectedLogType === 'python_errors' ? 'btn-dark' : 'btn-outline-dark' }}">Python Errors</a>
                <a href="{{ route('cryptofuturesignals.logs.index', ['log_type' => 'laravel', 'lines' => $lines]) }}" class="btn btn-sm {{ $selectedLogType === 'laravel' ? 'btn-dark' : 'btn-outline-dark' }}">Laravel Logs</a>
            </div>
        </div>
    </div>

    @if ($selectedLogType === 'coindcx_prices')
        <div class="alert alert-info">
            This log records every CoinDCX price fetch call with requested pairs, found/missing status, and returned prices.
        </div>
    @endif

    <div class="card metric-card">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h2 class="h5 mb-1">{{ $selectedLogLabel }}</h2>
                <div class="small text-muted">{{ $filePathDisplay }}</div>
            </div>
            <div class="text-md-end">
                @if ($fileExists)
                    <span class="badge text-bg-success">file exists</span>
                @else
                    <span class="badge text-bg-warning">missing</span>
                @endif
                <div class="small text-muted mt-1">Displaying {{ count($logLines) }} line{{ count($logLines) === 1 ? '' : 's' }}</div>
            </div>
        </div>
        <div class="card-body">
            @if (! $fileExists)
                <div class="alert alert-warning mb-0">Log file does not exist yet. Run the Python monitor or trigger the related process first.</div>
            @elseif (count($logLines) === 0)
                <div class="alert alert-secondary mb-0">No log entries found.</div>
            @else
                <pre class="bg-dark text-light rounded p-3 mb-0 overflow-auto" style="max-height: 70vh; white-space: pre; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><code>{{ implode("\n", $logLines) }}</code></pre>
            @endif
        </div>
    </div>
@endsection
