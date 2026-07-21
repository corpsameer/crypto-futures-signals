@if ($paginator->hasPages())
    <nav class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2" role="navigation" aria-label="Pagination Navigation">
        <div class="small text-muted">
            @if ($paginator->firstItem())
                Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} results
            @else
                Showing results
            @endif
        </div>

        <ul class="pagination pagination-sm mb-0 flex-wrap">
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true" aria-label="Previous">
                    <span class="page-link">&lsaquo; Previous</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous">&lsaquo; Previous</a>
                </li>
            @endif

            @if ($paginator->hasMorePages())
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next">Next &rsaquo;</a>
                </li>
            @else
                <li class="page-item disabled" aria-disabled="true" aria-label="Next">
                    <span class="page-link">Next &rsaquo;</span>
                </li>
            @endif
        </ul>
    </nav>
@endif
