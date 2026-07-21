<div class="card metric-card mb-4">
    <div class="card-body">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
            <div>
                <h2 class="h5 mb-1">Strategy Result Filters</h2>
                <p class="text-muted small mb-0">Filters apply to canonical strategy results only; account capital values remain all-time ledger values.</p>
            </div>
            @if ($filtersActive)
                <span class="badge text-bg-info align-self-start">Showing filtered strategy results</span>
            @endif
        </div>

        @if ($errors->any())
            <div class="alert alert-danger">
                <div class="fw-semibold mb-1">Please correct the filter values.</div>
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="GET" action="{{ $action }}" class="row g-3 align-items-end">
            <div class="col-6 col-md-3 col-xl-2">
                <label for="strategy_date_from" class="form-label">From Date</label>
                <input id="strategy_date_from" type="date" name="date_from" value="{{ $filters['date_from'] }}" class="form-control">
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="strategy_date_to" class="form-label">To Date</label>
                <input id="strategy_date_to" type="date" name="date_to" value="{{ $filters['date_to'] }}" class="form-control">
            </div>
            @foreach ([
                'trader' => ['Trader', $filterOptions['traders']],
                'direction' => ['Direction', $filterOptions['directions']],
                'symbol' => ['Symbol', $filterOptions['symbols']],
                'result_status' => ['Result', $filterOptions['resultStatuses']],
                'exit_event_type' => ['Exit Event', $filterOptions['exitEvents']],
                'post_sl_recovered' => ['Post-SL Recovered', $filterOptions['postSlRecovered']],
            ] as $name => [$label, $options])
                <div class="col-6 col-md-3 col-xl-2">
                    <label for="strategy_{{ $name }}" class="form-label">{{ $label }}</label>
                    <select id="strategy_{{ $name }}" name="{{ $name }}" class="form-select">
                        <option value="">All</option>
                        @foreach ($options as $value => $optionLabel)
                            <option value="{{ $value }}" @selected($filters[$name] === (string) $value)>{{ $optionLabel }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="{{ $clearUrl }}" class="btn btn-outline-secondary">Clear filters</a>
            </div>
        </form>
    </div>
</div>
