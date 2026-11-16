{{-- Tarjeta de un documento: miniatura (o ícono) que abre la vista previa. Parámetros: $p, $document, $compact. --}}
@php $url = route('patients.documents.show', [$p, $document]); @endphp
<div class="doc-card">
    @if($document->isImage())
        <button type="button" class="doc-thumb" data-preview="{{ $url }}" data-title="{{ $document->title }}" aria-label="Ver {{ $document->title }}">
            <img src="{{ $url }}" alt="{{ $document->title }}" loading="lazy">
        </button>
    @else
        <a href="{{ $url }}" target="_blank" rel="noopener" class="doc-thumb text-decoration-none" aria-label="Abrir {{ $document->title }}">
            <i class="bi {{ $document->icon }}"></i>
        </a>
    @endif
    <div class="doc-body">
        <div class="doc-title" title="{{ $document->title }}">{{ $document->title }}</div>
        <div class="text-muted">{{ $document->category_label }}@unless($compact ?? false) · {{ $document->taken_on?->format('d/m/Y') }}@endunless</div>
        @unless($compact ?? false)
            <div class="d-flex justify-content-between align-items-center mt-1">
                <span class="text-muted">{{ $document->size_label }}</span>
                <span>
                    <a href="{{ $url }}" target="_blank" rel="noopener" class="btn btn-sm btn-link p-0 me-2" title="Abrir en otra pestaña"><i class="bi bi-box-arrow-up-right"></i></a>
                    @if($can('patient_files.delete'))
                        <form method="POST" action="{{ route('patients.documents.destroy', [$p, $document]) }}" class="d-inline" onsubmit="return confirm('¿Eliminar «{{ e($document->title) }}»? No se puede deshacer.')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-link p-0 text-danger" type="submit" title="Eliminar"><i class="bi bi-trash"></i></button>
                        </form>
                    @endif
                </span>
            </div>
        @endunless
    </div>
</div>

@once
    <div class="modal fade" id="docPreview" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content bg-dark">
                <div class="modal-header border-0 text-white">
                    <h6 class="modal-title" id="docPreviewTitle"></h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body text-center p-2"><img id="docPreviewImg" src="" alt="" class="img-fluid rounded" style="max-height: 78vh"></div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('[data-preview]');
            if (!trigger) return;
            document.getElementById('docPreviewImg').src = trigger.dataset.preview;
            document.getElementById('docPreviewImg').alt = trigger.dataset.title;
            document.getElementById('docPreviewTitle').textContent = trigger.dataset.title;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('docPreview')).show();
        });
    </script>
@endonce
