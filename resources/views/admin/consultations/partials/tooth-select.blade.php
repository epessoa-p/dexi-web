{{-- Selector de pieza (FDI). Parámetros: $name, $id. --}}
<select name="{{ $name }}" id="{{ $id }}" class="form-select" aria-label="Pieza">
    <option value="">Pieza…</option>
    <optgroup label="Permanente">
        @foreach([[18, 11], [21, 28], [31, 38], [41, 48]] as [$from, $to])
            @foreach(range($from, $to) as $tooth)<option value="{{ $tooth }}">{{ $tooth }}</option>@endforeach
        @endforeach
    </optgroup>
    <optgroup label="Temporal">
        @foreach([[55, 51], [61, 65], [71, 75], [81, 85]] as [$from, $to])
            @foreach(range($from, $to) as $tooth)<option value="{{ $tooth }}">{{ $tooth }}</option>@endforeach
        @endforeach
    </optgroup>
</select>
