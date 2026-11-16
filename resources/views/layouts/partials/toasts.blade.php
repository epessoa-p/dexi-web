{{--
    Avisos emergentes: bajan arriba a la derecha (arriba al centro en el
    teléfono), se van solos y se cierran al tocarlos. Los mensajes de sesión
    `success`, `error`, `warning` e `info` salen así; desde JS:
    window.dexiToast('Guardado', 'success').
--}}
@php
    // Un error de negocio (withErrors(['error' => …])) también sale como aviso; los de formulario quedan junto al formulario.
    $onlyBusinessError = isset($errors) && $errors->has('error') && collect($errors->keys())->diff(['error'])->isEmpty();
    $toastFlashes = collect(['success', 'error', 'warning', 'info'])
        ->mapWithKeys(fn ($k) => [$k => session($k) ?? ($k === 'error' && $onlyBusinessError ? $errors->first('error') : null)])
        ->filter()->all();
@endphp
<div id="dexi-toasts" class="dexi-toasts" aria-live="polite" aria-atomic="false"></div>

<style>
    .dexi-toasts { position: fixed; top: 16px; right: 16px; z-index: 1090; display: flex; flex-direction: column; gap: 10px; width: min(380px, calc(100vw - 32px)); pointer-events: none; }
    @media (max-width: 575.98px) { .dexi-toasts { left: 16px; right: 16px; width: auto; } }
    .dexi-toast { --tone: #0d6efd; pointer-events: auto; cursor: pointer; display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px 12px 12px;
        background: var(--bs-body-bg, #fff); color: var(--bs-body-color, #212529); border-radius: 14px; border-left: 4px solid var(--tone);
        box-shadow: 0 10px 30px rgba(15, 23, 42, .16), 0 2px 6px rgba(15, 23, 42, .08); position: relative; overflow: hidden;
        animation: dexi-toast-in .32s cubic-bezier(.2, 1.3, .4, 1) both; }
    .dexi-toast.is-leaving { animation: dexi-toast-out .22s ease-in both; }
    .dexi-toast .dexi-toast-icon { flex: none; width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; font-size: 1.05rem;
        color: var(--tone); background: color-mix(in srgb, var(--tone) 14%, transparent); }
    .dexi-toast .dexi-toast-text { flex: 1; align-self: center; font-size: .925rem; line-height: 1.35; font-weight: 500; }
    .dexi-toast .dexi-toast-close { flex: none; border: 0; background: none; color: var(--bs-secondary-color, #6c757d); font-size: 1.1rem; line-height: 1; padding: 2px; }
    .dexi-toast .dexi-toast-bar { position: absolute; left: 0; bottom: 0; height: 3px; background: var(--tone); opacity: .35; animation: dexi-toast-bar linear both; }
    .dexi-toast:hover .dexi-toast-bar { animation-play-state: paused; }
    .dexi-toast.success { --tone: #198754; }
    .dexi-toast.error { --tone: #dc3545; }
    .dexi-toast.warning { --tone: #d48806; }
    .dexi-toast.info { --tone: #0d6efd; }
    @keyframes dexi-toast-in { from { opacity: 0; transform: translateY(-16px) scale(.97); } to { opacity: 1; transform: none; } }
    @keyframes dexi-toast-out { to { opacity: 0; transform: translateY(-10px) scale(.97); } }
    @keyframes dexi-toast-bar { from { width: 100%; } to { width: 0; } }
    @media (prefers-reduced-motion: reduce) { .dexi-toast, .dexi-toast.is-leaving, .dexi-toast .dexi-toast-bar { animation: none; } }
</style>

<script>
(function () {
    const icons = { success: 'bi-check-lg', error: 'bi-exclamation-lg', warning: 'bi-exclamation-triangle', info: 'bi-info-lg' };
    // Los errores quedan más tiempo: hay que leerlos. Pasar el mouse por encima los detiene.
    const durations = { success: 3500, info: 3500, warning: 6000, error: 6000 };

    window.dexiToast = function (message, kind = 'info') {
        const box = document.getElementById('dexi-toasts');
        if (!box || !message) return;
        kind = icons[kind] ? kind : 'info';

        const toast = document.createElement('div');
        toast.className = `dexi-toast ${kind}`;
        toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        toast.innerHTML = `<span class="dexi-toast-icon"><i class="bi ${icons[kind]}"></i></span><span class="dexi-toast-text"></span>`
            + '<button type="button" class="dexi-toast-close" aria-label="Cerrar"><i class="bi bi-x"></i></button><span class="dexi-toast-bar"></span>';
        toast.querySelector('.dexi-toast-text').textContent = message;

        const bar = toast.querySelector('.dexi-toast-bar');
        bar.style.animationDuration = `${durations[kind]}ms`;
        const close = () => {
            if (toast.classList.contains('is-leaving')) return;
            toast.classList.add('is-leaving');
            toast.addEventListener('animationend', () => toast.remove(), { once: true });
            setTimeout(() => toast.remove(), 400);
        };
        // Se va cuando termina la barra (que se pausa con el mouse encima).
        bar.addEventListener('animationend', close);
        toast.addEventListener('click', close);

        box.prepend(toast);
        while (box.children.length > 3) box.lastElementChild.remove();
    };

    const flashes = @json($toastFlashes);
    const show = () => Object.entries(flashes).forEach(([kind, message]) => window.dexiToast(message, kind));
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', show) : show();
})();
</script>
