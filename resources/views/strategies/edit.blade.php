@extends('layouts.app')

@section('title', 'Edit Strategy | Crypto Futures Signal Analyzer')

@section('content')
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <div class="mb-2"><a href="{{ route('cryptofuturesignals.strategies.show', $strategy) }}" class="text-decoration-none">&larr; Back to strategy details</a></div>
            <h1 class="h3 mb-1">Edit Strategy</h1>
            <p class="text-muted mb-0">Update safe strategy metadata and future allocation settings only.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-start">
            <span class="badge text-bg-dark fs-6">{{ $strategy->code }}</span>
            <span class="badge fs-6 {{ $strategy->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $strategy->is_active ? 'Active' : 'Inactive' }}</span>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <div class="fw-semibold mb-1">Please correct the highlighted fields.</div>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card metric-card">
        <div class="card-body">
            <form method="POST" action="{{ route('cryptofuturesignals.strategies.update', $strategy) }}" novalidate>
                @csrf
                @method('PUT')

                <div class="row g-4">
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="strategy_code">Strategy Code</label>
                        <input type="text" id="strategy_code" class="form-control" value="{{ $strategy->code }}" readonly>
                        <div class="form-text">The strategy code identifies the code/config-based rules and cannot be edited here.</div>
                    </div>

                    <div class="col-12 col-lg-6">
                        <label class="form-label fw-semibold" for="name">Name</label>
                        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $strategy->name) }}" maxlength="191" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12 col-lg-6">
                        <label class="form-label fw-semibold" for="allocation_percent">Allocation %</label>
                        <input type="number" id="allocation_percent" name="allocation_percent" class="form-control @error('allocation_percent') is-invalid @enderror" value="{{ old('allocation_percent', $strategy->allocation_percent) }}" min="0.0001" max="100" step="0.0001" required>
                        <div class="form-text">Controls the percentage of the strategy’s available capital allocated per eligible future trade.</div>
                        @error('allocation_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold" for="description">Description</label>
                        <textarea id="description" name="description" class="form-control @error('description') is-invalid @enderror" rows="5">{{ old('description', $strategy->description) }}</textarea>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <input type="hidden" name="is_active" value="0">
                        <div class="form-check form-switch">
                            <input type="checkbox" id="is_active" name="is_active" value="1" class="form-check-input @error('is_active') is-invalid @enderror" @checked(old('is_active', $strategy->is_active) == '1')>
                            <label class="form-check-label fw-semibold" for="is_active">Active for future processing</label>
                            @error('is_active')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-column flex-sm-row gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Save Strategy</button>
                    <a href="{{ route('cryptofuturesignals.strategies.show', $strategy) }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
