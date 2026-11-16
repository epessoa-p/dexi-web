{{--
    Odontograma interactivo (FDI). Parámetros:
      $patient, $editable (bool), $consultation (opcional: lo registrado queda ligado a la consulta abierta).
    Rojo = hallazgo a tratar · Azul = tratamiento existente o realizado.
    Emite el evento `odontogram:tooth` {tooth, surface} al tocar una pieza sin herramienta (la consulta lo usa
    para cargar un procedimiento en esa pieza).
--}}
@php
    use App\Support\Odontogram;
    $editable = $editable ?? false;
    $consultation = $consultation ?? null;
    $config = [
        'state' => (object) Odontogram::state($patient),
        'summary' => Odontogram::summary($patient),
        'catalog' => Odontogram::catalog(),
        'surfaces' => Odontogram::SURFACES,
        'rows' => ['permanente' => Odontogram::rows('permanente'), 'temporal' => Odontogram::rows('temporal')],
        'dentition' => Odontogram::defaultDentition($patient),
        'editable' => $editable,
        'consultation' => $consultation?->id,
        'urls' => [
            'store' => route('odontogram.store', $patient),
            'tooth' => route('odontogram.tooth', [$patient, 0]),
            'resolve' => route('odontogram.resolve', [$patient, 0]),
        ],
        'csrf' => csrf_token(),
    ];
@endphp
<div class="odonto" id="odontogram" data-config="{{ json_encode($config) }}">
    <div class="odonto-toolbar">
        <div class="btn-group btn-group-sm" role="group" aria-label="Dentición">
            @foreach(['permanente' => 'Permanente', 'mixta' => 'Mixta', 'temporal' => 'Temporal'] as $key => $label)
                <input type="radio" class="btn-check" name="odonto-dentition" id="odonto-{{ $key }}" value="{{ $key }}" @checked($config['dentition'] === $key)>
                <label class="btn btn-outline-secondary" for="odonto-{{ $key }}">{{ $label }}</label>
            @endforeach
        </div>
        <div class="odonto-summary small text-muted" aria-live="polite"></div>
    </div>

    @if($editable)
        <div class="odonto-tools" role="toolbar" aria-label="Herramientas">
            <button type="button" class="odonto-tool is-active" data-tool=""><i class="bi bi-hand-index"></i> Ver pieza</button>
            <span class="odonto-tools-label text-danger">Hallazgos</span>
            @foreach(Odontogram::CONDITIONS as $key => [$label, $type, $scope])
                @if($type === 'hallazgo')
                    <button type="button" class="odonto-tool tool-red" data-tool="{{ $key }}" title="{{ $scope === 'cara' ? 'Toca la cara' : 'Toca la pieza' }}">{{ $label }}</button>
                @endif
            @endforeach
            <span class="odonto-tools-label text-primary">Tratamientos</span>
            @foreach(Odontogram::CONDITIONS as $key => [$label, $type, $scope])
                @if($type === 'tratamiento')
                    <button type="button" class="odonto-tool tool-blue" data-tool="{{ $key }}" title="{{ $scope === 'cara' ? 'Toca la cara' : 'Toca la pieza' }}">{{ $label }}</button>
                @endif
            @endforeach
        </div>
        <div class="odonto-hint small text-muted" aria-live="polite">Elige una herramienta y toca la cara o la pieza. Con «Ver pieza», toca una pieza para ver su historia.</div>
    @endif

    <div class="odonto-canvas"><svg class="odonto-svg" role="group" aria-label="Odontograma"></svg></div>

    <div class="odonto-legend small">
        <span><span class="sw sw-red"></span> Hallazgo a tratar</span>
        <span><span class="sw sw-blue"></span> Tratamiento existente / realizado</span>
        <span><svg width="14" height="14"><path d="M2 2L12 12M12 2L2 12" stroke="#2563eb" stroke-width="2"/></svg> Ausente</span>
        <span><svg width="14" height="14"><path d="M2 2L12 12M12 2L2 12" stroke="#dc3545" stroke-width="2"/></svg> Extracción indicada</span>
        <span><svg width="14" height="14"><circle cx="7" cy="7" r="5.5" fill="none" stroke="#2563eb" stroke-width="2"/></svg> Corona</span>
        <span><svg width="14" height="14"><path d="M7 1V13" stroke="#2563eb" stroke-width="3"/></svg> Endodoncia</span>
    </div>

    {{-- Panel de la pieza --}}
    <div class="offcanvas offcanvas-end" tabindex="-1" id="toothPanel" aria-labelledby="toothPanelTitle">
        <div class="offcanvas-header border-bottom">
            <div>
                <h5 class="offcanvas-title mb-0" id="toothPanelTitle">Pieza</h5>
                <div class="small text-muted" id="toothPanelName"></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
        </div>
        <div class="offcanvas-body">
            @if($consultation)
                <button type="button" class="btn btn-primary w-100 mb-3" id="toothUseInConsultation"><i class="bi bi-plus-lg"></i> Registrar algo hecho en esta pieza</button>
            @endif
            <h6 class="fw-bold small text-uppercase text-muted">Estado actual</h6>
            <ul class="list-group list-group-flush mb-3" id="toothCurrent"></ul>
            <h6 class="fw-bold small text-uppercase text-muted">Historia</h6>
            <ul class="list-group list-group-flush small" id="toothHistory"></ul>
        </div>
    </div>
</div>

@once
@push('styles')
<style>
    .odonto-toolbar { display: flex; justify-content: space-between; align-items: center; gap: .5rem; flex-wrap: wrap; margin-bottom: .6rem; }
    .odonto-tools { display: flex; flex-wrap: wrap; gap: .3rem; align-items: center; margin-bottom: .4rem; }
    .odonto-tools-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin: 0 .2rem 0 .5rem; }
    .odonto-tool { border: 1px solid var(--dx-border-strong); background: #fff; border-radius: 999px; padding: .22rem .65rem; font-size: .78rem; font-weight: 600; color: #44565b; }
    .odonto-tool.tool-red { border-color: #f1aeb5; color: #b02a37; }
    .odonto-tool.tool-blue { border-color: #b6ccf5; color: #1d4ed8; }
    .odonto-tool.is-active { background: var(--dx-text); border-color: var(--dx-text); color: #fff; }
    .odonto-tool.tool-red.is-active { background: #dc3545; border-color: #dc3545; }
    .odonto-tool.tool-blue.is-active { background: #2563eb; border-color: #2563eb; }
    .odonto-hint { margin-bottom: .5rem; }
    .odonto-canvas { overflow-x: auto; background: #fbfdfd; border: 1px solid var(--dx-border); border-radius: 12px; padding: .5rem; }
    .odonto-svg { display: block; margin: 0 auto; }
    .odonto-svg .face { fill: #fff; stroke: #9fb0b5; stroke-width: 1; cursor: pointer; transition: fill .1s; }
    .odonto-svg .face:hover { fill: #e6f6f5; }
    .odonto-svg .tooth:focus-visible { outline: none; }
    .odonto-svg .tooth:focus-visible .frame { stroke: var(--brand-base); stroke-width: 2.5; }
    .odonto-svg .frame { fill: none; stroke: #6b7c81; stroke-width: 1.2; pointer-events: none; }
    .odonto-svg .num { font: 600 11px Inter, sans-serif; fill: #44565b; text-anchor: middle; cursor: pointer; }
    .odonto-svg .num.has { fill: var(--brand-dark); }
    .odonto-svg .tag { font: 700 9px Inter, sans-serif; text-anchor: middle; pointer-events: none; }
    .odonto-svg .is-missing .face { fill: #f1f4f5; }
    .odonto-legend { display: flex; flex-wrap: wrap; gap: .4rem 1rem; margin-top: .6rem; color: var(--dx-muted); }
    .odonto-legend > span { display: inline-flex; align-items: center; gap: .35rem; }
    .odonto-legend .sw { width: 14px; height: 14px; border-radius: 3px; display: inline-block; }
    .sw-red { background: #dc3545; } .sw-blue { background: #2563eb; }
    .odonto.is-busy { opacity: .7; pointer-events: none; }
</style>
@endpush
@push('scripts')
<script>
(function () {
    const root = document.getElementById('odontogram');
    if (!root) return;
    const cfg = JSON.parse(root.dataset.config);
    const svg = root.querySelector('.odonto-svg');
    const NS = 'http://www.w3.org/2000/svg';
    const RED = '#dc3545', BLUE = '#2563eb';
    const catalog = Object.fromEntries(cfg.catalog.map((c) => [c.key, c]));
    let state = cfg.state, tool = '', dentition = cfg.dentition;

    const S = 40, GAP = 8, ROOT = 14, LABEL = 16, MID = 14;
    const el = (name, attrs = {}, parent) => {
        const node = document.createElementNS(NS, name);
        Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
        parent?.appendChild(node);
        return node;
    };
    const colorOf = (condition) => (catalog[condition]?.type === 'hallazgo' ? RED : BLUE);

    // Qué cara es cada trapecio según la arcada y el lado (la mesial mira a la línea media).
    function faceMap(tooth) {
        const q = Math.floor(tooth / 10);
        const upper = [1, 2, 5, 6].includes(q);
        const rightSide = [1, 4, 5, 8].includes(q);
        return { top: upper ? 'V' : 'L', bottom: upper ? 'L' : 'V', left: rightSide ? 'D' : 'M', right: rightSide ? 'M' : 'D', center: 'O' };
    }

    function drawTooth(tooth, x, y, upper) {
        const g = el('g', { class: 'tooth', transform: `translate(${x},${y})`, tabindex: 0, role: 'button', 'data-tooth': tooth }, svg);
        const info = state[tooth] || { whole: [], surfaces: {} };
        const whole = info.whole || [];
        if (whole.includes('ausente')) g.classList.add('is-missing');
        const faces = faceMap(tooth);
        const shapes = {
            top: `0,0 ${S},0 ${S - 13},13 13,13`,
            bottom: `0,${S} ${S},${S} ${S - 13},${S - 13} 13,${S - 13}`,
            left: `0,0 13,13 13,${S - 13} 0,${S}`,
            right: `${S},0 ${S - 13},13 ${S - 13},${S - 13} ${S},${S}`,
        };
        Object.entries(shapes).forEach(([pos, points]) => paintFace(el('polygon', { points, class: 'face', 'data-surface': faces[pos] }, g), info, faces[pos]));
        paintFace(el('rect', { x: 13, y: 13, width: S - 26, height: S - 26, class: 'face', 'data-surface': 'O' }, g), info, 'O');
        el('rect', { x: 0, y: 0, width: S, height: S, class: 'frame', rx: 2 }, g);

        // Símbolos de pieza entera.
        whole.forEach((condition) => {
            const c = catalog[condition]; if (!c) return;
            const color = colorOf(condition);
            const rootY1 = upper ? 0 : S, rootY2 = upper ? -ROOT + 2 : S + ROOT - 2;
            if (c.symbol === 'missing' || c.symbol === 'x') {
                el('path', { d: `M-3 -3L${S + 3} ${S + 3}M${S + 3} -3L-3 ${S + 3}`, stroke: color, 'stroke-width': 3, 'stroke-linecap': 'round', 'pointer-events': 'none' }, g);
            } else if (c.symbol === 'root') {
                el('line', { x1: S / 2, y1: rootY1, x2: S / 2, y2: rootY2, stroke: color, 'stroke-width': 4, 'stroke-linecap': 'round', 'pointer-events': 'none' }, g);
                if (condition === 'resto_radicular') el('text', { x: S / 2, y: S / 2 + 3, class: 'tag', fill: color }, g).textContent = 'RR';
            } else if (c.symbol === 'ring') {
                el('circle', { cx: S / 2, cy: S / 2, r: S / 2 + 3, fill: 'none', stroke: color, 'stroke-width': 2.5, 'pointer-events': 'none' }, g);
            } else if (c.symbol === 'implant') {
                el('rect', { x: 4, y: 4, width: S - 8, height: S - 8, fill: 'none', stroke: color, 'stroke-width': 2.5, 'pointer-events': 'none' }, g);
                el('text', { x: S / 2, y: upper ? -4 : S + 11, class: 'tag', fill: color }, g).textContent = 'IMP';
            } else if (c.symbol === 'bar') {
                el('line', { x1: -GAP / 2, y1: upper ? S + 3 : -3, x2: S + GAP / 2, y2: upper ? S + 3 : -3, stroke: color, 'stroke-width': 3, 'pointer-events': 'none' }, g);
            } else if (c.symbol === 'tag') {
                el('text', { x: S - 3, y: upper ? -3 : S + 10, class: 'tag', fill: color }, g).textContent = condition === 'movilidad' ? 'M' : 'E';
            }
        });

        const num = el('text', { x: S / 2, y: upper ? -ROOT - 4 : S + ROOT + 12, class: 'num' + ((info.count || 0) ? ' has' : '') }, g);
        num.textContent = tooth;
        el('title', {}, g).textContent = `Pieza ${tooth}`;
    }

    function paintFace(node, info, surface) {
        const condition = info.surfaces?.[surface];
        if (!condition) return;
        const c = catalog[condition];
        const color = colorOf(condition);
        if (c?.symbol === 'outline') {
            node.style.fill = color + '22';
            node.style.stroke = color; node.style.strokeWidth = 2;
        } else {
            node.style.fill = color;
        }
        node.setAttribute('data-condition', condition);
    }

    function render() {
        svg.innerHTML = '';
        const sets = dentition === 'mixta' ? [cfg.rows.permanente[0], cfg.rows.temporal[0], cfg.rows.temporal[1], cfg.rows.permanente[1]]
            : (cfg.rows[dentition] || cfg.rows.permanente);
        const widest = Math.max(...sets.map((r) => r.length));
        const width = widest * (S + GAP) + MID + 8;
        const rowH = S + ROOT * 2 + LABEL + 6;
        svg.setAttribute('viewBox', `0 0 ${width} ${sets.length * rowH + 4}`);
        svg.setAttribute('width', width);
        sets.forEach((row, r) => {
            const upper = [1, 2, 5, 6].includes(Math.floor(row[0] / 10));
            const offset = (widest - row.length) * (S + GAP) / 2 + 4;
            const y = r * rowH + ROOT + LABEL;
            row.forEach((tooth, i) => {
                const half = i >= row.length / 2 ? MID : 0;
                drawTooth(tooth, offset + i * (S + GAP) + half, y, upper);
            });
            // Línea media.
            el('line', { x1: offset + row.length / 2 * (S + GAP) + MID / 2 - GAP / 2, y1: y - 6, x2: offset + row.length / 2 * (S + GAP) + MID / 2 - GAP / 2, y2: y + S + 6, stroke: '#cdd8db', 'stroke-dasharray': '3 3' }, svg);
        });
        renderSummary();
    }

    function renderSummary() {
        const s = cfg.summary || {};
        const parts = [];
        parts.push(s.findings ? `<strong class="text-danger">${s.findings} ${s.findings === 1 ? 'hallazgo' : 'hallazgos'}</strong> en ${s.teeth_with_findings} ${s.teeth_with_findings === 1 ? 'pieza' : 'piezas'}` : 'Sin hallazgos pendientes');
        if (s.treatments) parts.push(`${s.treatments} ${s.treatments === 1 ? 'tratamiento' : 'tratamientos'}`);
        if (s.missing) parts.push(`${s.missing} ${s.missing === 1 ? 'ausente' : 'ausentes'}`);
        root.querySelector('.odonto-summary').innerHTML = parts.join(' · ');
    }

    async function request(url, body) {
        root.classList.add('is-busy');
        try {
            const response = await fetch(url, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': cfg.csrf, Accept: 'application/json' } });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) { window.dexiToast?.(Object.values(data.errors || {})[0]?.[0] || 'No se pudo guardar.', 'error'); return null; }
            state = data.state; cfg.summary = data.summary;
            render();
            if (data.message) window.dexiToast?.(data.message, 'success');
            return data;
        } finally { root.classList.remove('is-busy'); }
    }

    function apply(tooth, surface) {
        const c = catalog[tool];
        const body = new FormData();
        body.append('tooth', tooth);
        body.append('condition', tool);
        if (c.scope === 'cara') {
            if (!surface) { window.dexiToast?.('Toca una cara de la pieza (no el número).', 'warning'); return; }
            body.append('surfaces[]', surface);
        }
        if (cfg.consultation) body.append('consultation_id', cfg.consultation);
        request(cfg.urls.store, body);
    }

    // Panel de la pieza.
    const panelEl = document.getElementById('toothPanel');
    let panelTooth = null, panelSurface = null;
    async function openPanel(tooth, surface) {
        panelTooth = tooth; panelSurface = surface;
        const data = await fetch(cfg.urls.tooth.replace(/\/0$/, '/' + tooth), { headers: { Accept: 'application/json' } }).then((r) => r.json());
        document.getElementById('toothPanelTitle').textContent = `Pieza ${tooth}`;
        document.getElementById('toothPanelName').textContent = data.name;
        const item = (e, current) => {
            const li = document.createElement('li');
            li.className = 'list-group-item px-0 d-flex gap-2 align-items-start';
            li.innerHTML = `<span class="d-inline-block rounded-1 mt-1 flex-shrink-0" style="width:.75rem;height:.75rem;background:${e.type === 'hallazgo' ? RED : BLUE}"></span>
                <span class="flex-fill"><span class="fw-semibold"></span><span class="d-block small text-muted"></span></span>`;
            li.querySelector('.fw-semibold').textContent = e.label + (e.surface_label ? ` · ${e.surface_label}` : '');
            li.querySelector('.text-muted').textContent = [e.date, e.professional, e.consultation, e.notes, current ? null : `quitado ${e.resolved}: ${e.resolution}`].filter(Boolean).join(' · ');
            if (current && cfg.editable) {
                const button = document.createElement('button');
                button.type = 'button'; button.className = 'btn btn-sm btn-light text-danger'; button.title = 'Quitar (queda en la historia)';
                button.innerHTML = '<i class="bi bi-x-lg"></i>';
                button.addEventListener('click', async () => {
                    const reason = prompt('¿Por qué se quita? (ej. error al marcar, ya no está)', 'Error al marcar');
                    if (reason === null) return;
                    const body = new FormData(); body.append('reason', reason);
                    if (await request(cfg.urls.resolve.replace(/\/0\/quitar$/, `/${e.id}/quitar`), body)) openPanel(tooth, surface);
                });
                li.appendChild(button);
            }
            return li;
        };
        const current = document.getElementById('toothCurrent'), history = document.getElementById('toothHistory');
        current.innerHTML = ''; history.innerHTML = '';
        data.current.forEach((e) => current.appendChild(item(e, true)));
        data.history.forEach((e) => history.appendChild(item(e, false)));
        if (!data.current.length) current.innerHTML = '<li class="list-group-item px-0 text-muted small">Sana / sin registros.</li>';
        if (!data.history.length) history.innerHTML = '<li class="list-group-item px-0 text-muted">Sin cambios anteriores.</li>';
        bootstrap.Offcanvas.getOrCreateInstance(panelEl).show();
    }
    document.getElementById('toothUseInConsultation')?.addEventListener('click', () => {
        bootstrap.Offcanvas.getOrCreateInstance(panelEl).hide();
        document.dispatchEvent(new CustomEvent('odontogram:tooth', { detail: { tooth: panelTooth, surface: panelSurface } }));
    });

    svg.addEventListener('click', (event) => {
        const g = event.target.closest('.tooth'); if (!g) return;
        const tooth = Number(g.dataset.tooth);
        const surface = event.target.dataset?.surface || null;
        if (tool && cfg.editable) apply(tooth, surface); else openPanel(tooth, surface);
    });
    svg.addEventListener('keydown', (event) => {
        const g = event.target.closest('.tooth');
        if (g && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); const t = Number(g.dataset.tooth); tool && cfg.editable ? apply(t, 'O') : openPanel(t, null); }
    });

    root.querySelectorAll('[data-tool]').forEach((button) => button.addEventListener('click', () => {
        tool = button.dataset.tool;
        root.querySelectorAll('[data-tool]').forEach((b) => b.classList.toggle('is-active', b === button));
        const c = catalog[tool];
        root.querySelector('.odonto-hint').textContent = !tool ? 'Toca una pieza para ver su estado e historia.'
            : (c.scope === 'cara' ? `${c.label}: toca la cara afectada.` : `${c.label}: toca la pieza.`);
    }));
    root.querySelectorAll('[name=odonto-dentition]').forEach((radio) => radio.addEventListener('change', () => { dentition = radio.value; render(); }));

    render();
})();
</script>
@endpush
@endonce
