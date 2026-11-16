{{--
    Errores de validación de los formularios cortos (movimientos, transferencias,
    caja…) que no muestran cada error junto a su campo. El error de negocio
    `error` sale como aviso emergente (layouts/partials/toasts).
--}}
@if(collect($errors->keys())->diff(['error'])->isNotEmpty())
    <div class="alert alert-danger alert-dismissible fade show d-flex gap-2" role="alert">
        <i class="bi bi-exclamation-circle"></i>
        <div>
            @if($errors->count() === 1)
                {{ $errors->first() }}
            @else
                <ul class="mb-0 ps-3">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
@endif
