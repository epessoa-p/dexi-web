@php
    /**
     * Renderiza un campo de configuración según su definición.
     * Espera: $key, $definition, $values, y opcionalmente $plans (para options => 'plans').
     */
    use App\Support\Brand;

    $field = str_replace('.', '_', $key);
    $current = old($field, $values[$key] ?? $definition['default'] ?? null);
    $input = $definition['input'];

    $options = $definition['options'] ?? [];
    if ($options === 'timezones') {
        $options = collect(DateTimeZone::listIdentifiers())->mapWithKeys(fn ($tz) => [$tz => $tz])->all();
    } elseif ($options === 'plans') {
        $options = ($plans ?? collect())->all();
    }
@endphp

<div class="col-md-6">
    @if($input === 'switch')
        <div class="form-check form-switch pt-4">
            <input type="hidden" name="{{ $field }}" value="0">
            <input class="form-check-input" type="checkbox" id="{{ $field }}" name="{{ $field }}" value="1" {{ $current ? 'checked' : '' }}>
            <label class="form-check-label" for="{{ $field }}">{{ $definition['label'] }}</label>
        </div>
    @else
        <label class="form-label" for="{{ $field }}">{{ $definition['label'] }}</label>

        @if($input === 'select')
            <select class="form-select @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}">
                <option value="">— Sin definir —</option>
                @foreach($options as $value => $label)
                    <option value="{{ $value }}" {{ (string) $current === (string) $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>

        @elseif($input === 'textarea')
            <textarea class="form-control @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}" rows="3">{{ $current }}</textarea>

        @elseif($input === 'number')
            <input type="number" class="form-control @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}" value="{{ $current }}">

        @elseif($input === 'time')
            <input type="time" class="form-control @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}" value="{{ $current }}">

        @elseif($input === 'image')
            @php $imageUrl = Brand::resolveUrl($current); @endphp
            @if($imageUrl)
                <div class="d-flex align-items-center gap-3 mb-2">
                    <img src="{{ $imageUrl }}" alt="{{ $definition['label'] }}" style="height: 46px; width: auto; max-width: 160px; object-fit: contain;">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="{{ $field }}_remove" name="{{ $field }}_remove" value="1">
                        <label class="form-check-label small text-danger" for="{{ $field }}_remove">Quitar imagen</label>
                    </div>
                </div>
            @endif
            <input type="file" accept="image/*" class="form-control @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}">

        @else
            <input type="text" class="form-control @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}" value="{{ $current }}">
        @endif
    @endif

    @if(!empty($definition['help']))
        <div class="form-text">{{ $definition['help'] }}</div>
    @endif

    @error($field)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>
