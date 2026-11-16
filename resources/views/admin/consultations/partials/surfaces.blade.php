{{-- Caras de la pieza como chips (M, O, D, V, L). Parámetro: $prefix (id único). --}}
<div class="d-flex gap-1 flex-wrap" role="group" aria-label="Caras">
    @foreach(['M' => 'Mesial', 'O' => 'Oclusal / incisal', 'D' => 'Distal', 'V' => 'Vestibular', 'L' => 'Lingual / palatino'] as $key => $label)
        <input type="checkbox" class="btn-check" name="surfaces[]" value="{{ $key }}" id="{{ $prefix }}-surface-{{ $key }}" autocomplete="off">
        <label class="btn btn-outline-secondary btn-sm px-2" for="{{ $prefix }}-surface-{{ $key }}" title="{{ $label }}">{{ $key }}</label>
    @endforeach
</div>
