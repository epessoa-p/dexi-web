{{--
    Botón «Enviar por WhatsApp» (wa.me). Abre WhatsApp con el texto y registra el envío.
    Uso: @include('admin.communications.partials.sender', ['kind' => 'recordatorio', 'patient' => $p,
         'message' => $texto, 'refs' => ['appointment_id' => 1], 'target' => '#msg-x' (opcional), 'sent' => $communication|null,
         'class' => 'btn-sm', 'label' => 'Enviar'])
--}}
@php
    $phone = $patient->whatsapp_link ? substr($patient->whatsapp_link, strlen('https://wa.me/')) : null;
    $payload = array_merge(['kind' => $kind, 'patient_id' => $patient->id, 'message' => $message], $refs ?? []);
    $sent = $sent ?? null;
@endphp
@if($phone)
    <button type="button" class="btn {{ $sent ? 'btn-outline-success' : 'btn-success' }} {{ $class ?? '' }} js-wa-send"
            data-phone="{{ $phone }}" data-payload="{{ json_encode($payload) }}" @isset($target) data-message-target="{{ $target }}" @endisset
            title="{{ $sent ? 'Ya se envió: tocar para enviar de nuevo' : 'Abre WhatsApp con el mensaje listo' }}">
        <i class="bi bi-{{ $sent ? 'check2-all' : 'whatsapp' }}"></i>
        <span class="js-wa-label">{{ $sent ? 'Enviado ' . $sent->created_at->format('H:i') : ($label ?? 'Enviar') }}</span>
    </button>
@else
    <a href="{{ route('patients.edit', $patient) }}" class="btn btn-light {{ $class ?? '' }}" title="El paciente no tiene celular: agrégalo en su ficha"><i class="bi bi-telephone-x"></i> Sin celular</a>
@endif

@once
    @push('scripts')
        <script>
            (() => {
                const logUrl = @json(route('communications.send'));
                const token = @json(csrf_token());
                document.addEventListener('click', (event) => {
                    const button = event.target.closest('.js-wa-send');
                    if (!button) return;
                    const payload = JSON.parse(button.dataset.payload);
                    const editor = button.dataset.messageTarget ? document.querySelector(button.dataset.messageTarget) : null;
                    if (editor) payload.message = editor.value.trim();
                    if (!payload.message) { window.dexiToast?.('Escribe el mensaje antes de enviarlo.', 'warning'); return; }

                    // Se abre primero (dentro del clic) para que el navegador no lo bloquee.
                    window.open('https://wa.me/' + button.dataset.phone + '?text=' + encodeURIComponent(payload.message), '_blank', 'noopener');

                    fetch(logUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                        body: JSON.stringify(payload),
                    })
                        .then((response) => response.ok ? response.json() : Promise.reject(response))
                        .then((data) => {
                            button.classList.replace('btn-success', 'btn-outline-success');
                            button.querySelector('i').className = 'bi bi-check2-all';
                            button.querySelector('.js-wa-label').textContent = 'Enviado ' + data.at;
                            button.closest('[data-wa-row]')?.classList.add('is-sent');
                            window.dexiToast?.('Listo: queda registrado como enviado.', 'success');
                        })
                        .catch(() => window.dexiToast?.('Se abrió WhatsApp, pero no se pudo registrar el envío.', 'warning'));
                });
            })();
        </script>
    @endpush
@endonce
