{{-- Estilos y comportamiento comunes de las pantallas de Finanzas. --}}
@once
<style>
    .finance-total-icon, .account-icon {
        width: 3rem; height: 3rem; border-radius: .8rem; display: grid; place-items: center;
        font-size: 1.35rem; color: #fff; flex-shrink: 0;
        background: linear-gradient(135deg, #e2b33a, #ca8a04);
        box-shadow: 0 4px 10px rgba(202, 138, 4, .25);
    }
    .account-icon.account-banco { background: linear-gradient(135deg, #4f8df5, #2563eb); box-shadow: 0 4px 10px rgba(37, 99, 235, .22); }
    .account-icon.account-caja_chica { background: linear-gradient(135deg, #34c38f, #059669); box-shadow: 0 4px 10px rgba(5, 150, 105, .22); }
    .account-icon.account-billetera { background: linear-gradient(135deg, #a78bfa, #7c3aed); box-shadow: 0 4px 10px rgba(124, 58, 237, .22); }
    .account-icon.account-capital { background: linear-gradient(135deg, #e2b33a, #ca8a04); }
    .account-icon.account-otro { background: linear-gradient(135deg, #94a3b8, #475569); box-shadow: 0 4px 10px rgba(71, 85, 105, .22); }
    .account-card { transition: transform .15s ease, box-shadow .15s ease; }
    .account-card:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1.2rem rgba(0, 0, 0, .08) !important; }
    .min-w-0 { min-width: 0; }
    .movement-cancelled td { text-decoration: line-through; color: #9aa0a6 !important; }
    .movement-cancelled td.no-strike { text-decoration: none; }
    .basics-row { display: flex; align-items: center; gap: .75rem; padding: .65rem 0; border-bottom: 1px solid #f0f0f0; }
    .basics-row:last-child { border-bottom: 0; }
    .basics-status { width: 2.1rem; height: 2.1rem; border-radius: 50%; display: grid; place-items: center; flex-shrink: 0; }
    .basics-status.paid { background: rgba(25, 135, 84, .12); color: #198754; }
    .basics-status.pending { background: rgba(234, 88, 12, .12); color: #c2410c; }
    .expense-tabs .nav-link { color: #555; }
    .expense-tabs .nav-link.active { background: #ca8a04; color: #fff; }
</style>
<script>
    // Un modal cuyo envío falló se vuelve a abrir con los errores.
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.modal[data-reopen]').forEach((el) => window.bootstrap?.Modal.getOrCreateInstance(el).show());
    });
</script>
@endonce
