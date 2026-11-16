{{--
    Menú por secciones desplegables, cada una con su color (App\Support\Navigation).
    Parámetros: $menu (secciones), $prefix (para que los id no choquen entre la
    barra lateral y el menú del celular). Abierta de entrada: la sección de la
    página actual; las demás recuerdan cómo las dejó el usuario (script en app.blade.php).
--}}
<nav class="nav-sections" aria-label="Menú principal">
    @foreach($menu as $section)
        @php $id = "{$prefix}-nav-{$section['key']}"; @endphp
        <div class="nav-section {{ $section['active'] ? 'is-open has-active' : '' }}"
             style="--sec: {{ $section['color'] }}" data-nav-section="{{ $section['key'] }}">
            <button class="nav-section-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $id }}"
                    aria-expanded="{{ $section['active'] ? 'true' : 'false' }}" aria-controls="{{ $id }}">
                <span class="nav-section-icon"><i class="bi {{ $section['icon'] }}"></i></span>
                <span class="nav-section-label">{{ $section['label'] }}</span>
                <span class="nav-section-count">{{ count($section['items']) }}</span>
                <i class="bi bi-chevron-down nav-section-chevron"></i>
            </button>
            <div class="collapse {{ $section['active'] ? 'show' : '' }}" id="{{ $id }}">
                <ul class="nav flex-column nav-section-items">
                    @foreach($section['items'] as $item)
                        <li class="nav-item">
                            <a class="nav-link app-link {{ $item['active'] ? 'active' : '' }}" href="{{ $item['url'] }}"
                               @if($item['active']) aria-current="page" @endif>
                                <i class="bi {{ $item['icon'] }}"></i> <span>{{ $item['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endforeach
</nav>
