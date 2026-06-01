@extends('layouts.app')

@php
    $parseStatusBadgeClasses = [
        \App\Models\PastedSignal::PARSE_STATUS_PENDING => 'text-bg-secondary',
        \App\Models\PastedSignal::PARSE_STATUS_PARSED => 'text-bg-success',
        \App\Models\PastedSignal::PARSE_STATUS_FAILED => 'text-bg-danger',
        \App\Models\PastedSignal::PARSE_STATUS_MANUALLY_CORRECTED => 'text-bg-info',
    ];
@endphp

@section('title', 'Pasted Signals | Crypto Futures Signal Analyzer')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Pasted Signals</h1>
            <p class="text-muted mb-0">Review raw manually pasted signals saved for your account.</p>
        </div>
        <a href="{{ route('cryptofuturesignals.signals.create') }}" class="btn btn-primary">Paste New Signal</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success" role="alert">
            {{ session('success') }}
        </div>
    @endif

    <div class="card metric-card">
        <div class="card-body p-0">
            @if ($signals->count() > 0)
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">ID</th>
                                <th scope="col">Pasted At</th>
                                <th scope="col">Trader</th>
                                <th scope="col">Parse Status</th>
                                <th scope="col">Source</th>
                                <th scope="col">Raw Text Preview</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($signals as $signal)
                                <tr>
                                    <td>{{ $signal->id }}</td>
                                    <td>{{ $signal->pasted_at?->format('Y-m-d H:i') ?? 'N/A' }}</td>
                                    <td>{{ $signal->trader_name ?: 'N/A' }}</td>
                                    <td>
                                        <span class="badge {{ $parseStatusBadgeClasses[$signal->parse_status] ?? 'text-bg-secondary' }}">
                                            {{ $signal->parse_status }}
                                        </span>

                                        @if ($signal->parse_status === \App\Models\PastedSignal::PARSE_STATUS_FAILED && $signal->parse_error)
                                            <div class="small text-danger mt-1" title="{{ $signal->parse_error }}">
                                                {{ \Illuminate\Support\Str::limit($signal->parse_error, 80) }}
                                            </div>
                                        @endif
                                    </td>
                                    <td>{{ $signal->source }}</td>
                                    <td class="text-break" style="white-space: pre-line; max-width: 32rem;">{{ \Illuminate\Support\Str::limit($signal->raw_text, 120) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="p-4 text-center text-muted">
                    No signals pasted yet.
                </div>
            @endif
        </div>
    </div>

    @if ($signals->hasPages())
        <div class="mt-4">
            {{ $signals->links() }}
        </div>
    @endif
@endsection
