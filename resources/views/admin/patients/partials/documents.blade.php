{{-- Pestaña «Documentos y fotos»: subir (arrastrando) y galería filtrable por tipo. --}}
@php
    $categories = \App\Models\PatientDocument::CATEGORIES;
    $current = request('category');
@endphp
<div class="row g-3">
    @if($can('patient_files.create'))
        <div class="col-lg-4">
            <form method="POST" action="{{ route('patients.documents.store', $p) }}" enctype="multipart/form-data" class="card">
                @csrf
                <div class="card-header"><i class="bi bi-cloud-arrow-up"></i> Subir archivos</div>
                <div class="card-body d-flex flex-column gap-3">
                    <label class="dropzone mb-0" id="dropzone">
                        <input type="file" name="files[]" id="files" multiple accept="image/*,application/pdf,.dcm" class="visually-hidden" required>
                        <i class="bi bi-cloud-arrow-up d-block"></i>
                        <span class="fw-semibold d-block" id="dropzone-text">Arrastra aquí o toca para elegir</span>
                        <small>Fotos, PDF o DICOM · hasta 10 archivos de 15 MB</small>
                    </label>
                    @error('files')<div class="text-danger small">{{ $message }}</div>@enderror
                    @error('files.*')<div class="text-danger small">{{ $message }}</div>@enderror
                    <div>
                        <label class="form-label" for="category">Tipo</label>
                        <select id="category" name="category" class="form-select">
                            @foreach($categories as $key => [$label])
                                <option value="{{ $key }}" @selected(old('category', $current ?: 'radiografia') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="title">Título <small class="text-muted fw-normal">(opcional)</small></label>
                        <input type="text" id="title" name="title" class="form-control" maxlength="150" value="{{ old('title') }}" placeholder="Panorámica inicial, consentimiento de extracción…">
                    </div>
                    <div>
                        <label class="form-label" for="taken_on">Fecha</label>
                        <input type="date" id="taken_on" name="taken_on" class="form-control" value="{{ old('taken_on', now()->toDateString()) }}" max="{{ now()->toDateString() }}">
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-upload"></i> Subir</button>
                    <p class="small text-muted mb-0"><i class="bi bi-lock"></i> Los archivos son privados: solo los ve el personal de la clínica con permiso.</p>
                </div>
            </form>
        </div>
    @endif

    <div class="{{ $can('patient_files.create') ? 'col-lg-8' : 'col-12' }}">
        <nav class="filter-chips mb-3" aria-label="Filtrar por tipo">
            <a href="{{ route('patients.show', [$p, 'tab' => 'documentos']) }}" class="filter-chip {{ ! $current ? 'active' : '' }}">Todos</a>
            @foreach($categories as $key => [$label, $icon])
                <a href="{{ route('patients.show', [$p, 'tab' => 'documentos', 'category' => $key]) }}" class="filter-chip {{ $current === $key ? 'active' : '' }}"><i class="bi {{ $icon }}"></i> {{ $label }}</a>
            @endforeach
        </nav>

        @if($documents->isEmpty())
            <div class="card">
                <div class="empty-state">
                    <div class="empty-icon"><i class="bi bi-images"></i></div>
                    <h5 class="mb-1">{{ $current ? 'No hay archivos de este tipo' : 'Sin documentos todavía' }}</h5>
                    <p class="small mb-0">Consentimientos firmados, radiografías y fotos del antes y después quedan aquí, ordenados por fecha.</p>
                </div>
            </div>
        @else
            <div class="doc-grid">
                @foreach($documents as $document)
                    @include('admin.patients.partials.document-card', ['document' => $document])
                @endforeach
            </div>
        @endif
    </div>
</div>

@if($can('patient_files.create'))
<script>
(function () {
    const zone = document.getElementById('dropzone');
    const input = document.getElementById('files');
    const text = document.getElementById('dropzone-text');
    const show = () => {
        const n = input.files.length;
        text.textContent = n === 0 ? 'Arrastra aquí o toca para elegir' : (n === 1 ? input.files[0].name : `${n} archivos elegidos`);
    };
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach((e) => zone.addEventListener(e, (ev) => { ev.preventDefault(); zone.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((e) => zone.addEventListener(e, (ev) => { ev.preventDefault(); zone.classList.remove('is-over'); }));
    zone.addEventListener('drop', (ev) => { input.files = ev.dataTransfer.files; show(); });
})();
</script>
@endif
