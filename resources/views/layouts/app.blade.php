<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Crypto Futures Signal Analyzer')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f6f8fb; }
        .navbar-brand { font-weight: 700; }
        .metric-card { border: 0; box-shadow: 0 0.5rem 1rem rgba(15, 23, 42, 0.08); }
        .metric-value { font-size: 2rem; font-weight: 700; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="{{ route('cryptofuturesignals.dashboard') }}">Crypto Futures Signal Analyzer</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavigation" aria-controls="mainNavigation" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNavigation">
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
                    <li class="nav-item"><a class="nav-link" href="{{ route('cryptofuturesignals.dashboard') }}">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('cryptofuturesignals.signals.create') }}">Paste Signal</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('cryptofuturesignals.signals.index') }}">Pasted Signals</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('cryptofuturesignals.trade-signals.index') }}">Structured Signals</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('cryptofuturesignals.trades.index') }}">Simulated Trades</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('cryptofuturesignals.traders.index') }}">Trader Performance</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('cryptofuturesignals.market-analysis.index') }}">Market Analysis</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('cryptofuturesignals.logs.index') }}">System Logs</a></li>
                    @auth
                        <li class="nav-item ms-lg-3">
                            <span class="navbar-text text-light">{{ auth()->user()->name }}</span>
                        </li>
                        <li class="nav-item ms-lg-2">
                            <form method="POST" action="{{ route('cryptofuturesignals.logout') }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-light btn-sm">Logout</button>
                            </form>
                        </li>
                    @endauth
                </ul>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        @yield('content')
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
