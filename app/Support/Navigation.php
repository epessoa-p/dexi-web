<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;

/**
 * El menú de la web: secciones desplegables, cada una con su color e ícono, y
 * dentro los enlaces que el usuario puede abrir (permiso del rol + módulo del
 * plan). La barra lateral y el menú del celular salen de aquí.
 *
 * Cada pantalla tiene UN solo lugar en el menú (el diseño completo y lo que
 * falta construir está en ../CHECKLIST-DEXI.md). Solo se muestra lo que
 * existe: un enlace nunca lleva a una pantalla vacía.
 */
final class Navigation
{
    /** key => [nombre, ícono, color] en el orden en que se muestran. */
    public const SECTIONS = [
        'inicio' => ['Inicio', 'bi-house-heart', '#0e9f9a'],
        'pacientes' => ['Pacientes', 'bi-people', '#2563eb'],
        'agenda' => ['Agenda', 'bi-calendar-week', '#7c3aed'],
        'clinica' => ['Atención clínica', 'bi-clipboard2-pulse', '#059669'],
        'ventas' => ['Ventas y caja', 'bi-cash-stack', '#ea580c'],
        'finanzas' => ['Finanzas', 'bi-bank', '#ca8a04'],
        'inventario' => ['Inventario', 'bi-box-seam', '#9333ea'],
        'compras' => ['Compras', 'bi-bag', '#0d9488'],
        'equipo' => ['Equipo', 'bi-person-badge', '#4f46e5'],
        'reportes' => ['Reportes', 'bi-bar-chart-line', '#0284c7'],
        'comunicaciones' => ['Comunicaciones', 'bi-megaphone', '#e11d48'],
        'configuracion' => ['Configuración', 'bi-sliders', '#475569'],
        'administracion' => ['Administración', 'bi-shield-lock', '#db2777'],
        'plataforma' => ['Plataforma', 'bi-globe2', '#0891b2'],
    ];

    /**
     * @return array<int, array{key: string, label: string, icon: string, color: string, active: bool,
     *                          items: array<int, array{label: string, icon: string, url: string, active: bool}>}>
     */
    public static function for(User $user, ?Company $company): array
    {
        $super = (bool) $user->is_super_admin;
        $can = fn (string $permission) => $user->hasPermissionInCompany($permission, $company);
        $module = fn (string ...$modules) => $super || collect($modules)->contains(fn ($m) => (bool) $company?->planAllows($m));
        // Las pantallas de una sola clínica se ocultan en el Modo Global del superadmin: primero «Entrar».
        $tenant = $company !== null;
        $use = fn (string $permission, string ...$modules) => $tenant && $can($permission) && $module(...$modules);

        $items = [
            'inicio' => [
                ['Dashboard', 'bi-speedometer2', 'dashboard', ['dashboard'], true],
            ],
            'pacientes' => [
                ['Pacientes', 'bi-people', 'patients.index', ['patients.*'], $tenant && $can('patients.view')],
            ],
            'agenda' => [
                ['Calendario', 'bi-calendar-week', 'appointments.index', ['appointments.index', 'appointments.create', 'appointments.show', 'appointments.edit'], $use('appointments.view', 'agenda')],
                ['Hoy y sala de espera', 'bi-hourglass-split', 'appointments.today', ['appointments.today'], $use('appointments.view', 'agenda')],
                ['Disponibilidad', 'bi-clock-history', 'availability.index', ['availability.*'], $use('schedules.view', 'agenda')],
            ],
            'clinica' => [
                ['Consultas', 'bi-clipboard2-pulse', 'consultations.index', ['consultations.*'], $use('consultations.view', 'historia_clinica')],
                ['Planes de tratamiento', 'bi-clipboard2-check', 'treatment-plans.index', ['treatment-plans.*'], $use('treatment_plans.view', 'tratamientos')],
                ['Laboratorio', 'bi-gem', 'lab-orders.index', ['lab-orders.*'], $use('lab_orders.view', 'laboratorio')],
            ],
            'ventas' => [
                ['Cobrar', 'bi-cart-plus', 'sales.create', ['sales.create'], $use('sales.create', 'ventas')],
                ['Ventas', 'bi-receipt', 'sales.index', ['sales.index', 'sales.show'], $use('sales.view', 'ventas')],
                ['Cuentas por cobrar', 'bi-hourglass-split', 'receivables.index', ['receivables.*'], $use('receivables.view', 'ventas')],
                ['Caja', 'bi-cash-coin', 'cash.index', ['cash.*'], $use('cash.view', 'caja')],
            ],
            'finanzas' => [
                ['Cuentas', 'bi-bank', 'finance-accounts.index', ['finance-accounts.*'], $use('accounts.view', 'finanzas')],
                ['Gastos', 'bi-wallet2', 'expenses.index', ['expenses.*'], $use('expenses.view', 'caja', 'finanzas')],
                ['Categorías de gasto', 'bi-tags', 'expense-categories.index', ['expense-categories.*'], $use('expense_categories.view', 'caja', 'finanzas')],
            ],
            'inventario' => [
                ['Productos e insumos', 'bi-box-seam', 'products.index', ['products.*'], $use('products.view', 'inventario')],
                ['Movimientos (kardex)', 'bi-arrow-down-up', 'stock-movements.index', ['stock-movements.*'], $use('inventory.view', 'inventario')],
            ],
            'compras' => [
                ['Compras', 'bi-bag-check', 'purchases.index', ['purchases.index', 'purchases.create', 'purchases.show'], $use('purchases.view', 'compras')],
                ['Cuentas por pagar', 'bi-hourglass-split', 'purchases.payables', ['purchases.payables'], $use('purchases.view', 'compras')],
                ['Proveedores', 'bi-truck', 'suppliers.index', ['suppliers.*'], $use('suppliers.view', 'finanzas', 'compras', 'inventario')],
            ],
            'equipo' => [
                ['Odontólogos y personal', 'bi-person-vcard', 'personal.index', ['personal.*'], $can('personal.view')],
                ['Comisiones y producción', 'bi-percent', 'commissions.index', ['commissions.index', 'commissions.professional', 'commissions.settlement', 'commissions.rules'], $use('commissions.view', 'comisiones')],
                ['Mis comisiones', 'bi-wallet', 'commissions.mine', ['commissions.mine'], $tenant && $module('comisiones') && ! $can('commissions.view') && \App\Models\Personal::where('user_id', $user->id)->exists()],
            ],
            'reportes' => [
                ['Reportes', 'bi-bar-chart-line', 'reports.index', ['reports.*'], $tenant && ($can('reports.view') || $can('reports.finance')) && $module('estadisticas')],
            ],
            'comunicaciones' => [
                ['Para enviar hoy', 'bi-whatsapp', 'communications.index', ['communications.index'], $use('communications.view', 'recordatorios')],
                ['Seguimientos y controles', 'bi-arrow-repeat', 'communications.followups', ['communications.followups'], $use('communications.view', 'recordatorios')],
                ['Enviados', 'bi-clock-history', 'communications.history', ['communications.history'], $use('communications.view', 'recordatorios')],
                ['Mensajes', 'bi-chat-square-text', 'communications.templates', ['communications.templates'], $use('communications.view', 'recordatorios')],
            ],
            'configuracion' => [
                ['Datos de la clínica', 'bi-hospital', 'settings.edit', ['settings.*'], $tenant && $can('settings.view')],
                ['Procedimientos y tarifario', 'bi-tags', 'procedures.index', ['procedures.*'], $tenant && $can('procedures.view')],
                ['Especialidades', 'bi-bookmark-star', 'specialties.index', ['specialties.*'], $tenant && $can('procedures.view')],
                ['Métodos de pago', 'bi-credit-card', 'payment-methods.index', ['payment-methods.*'], $use('payment_methods.view', 'caja', 'ventas')],
            ],
            'administracion' => [
                ['Consultorios', 'bi-diagram-2', 'branches.index', ['branches.*'], $can('branches.view')],
                ['Cajas', 'bi-safe', 'cajas.index', ['cajas.*'], $use('cajas.view', 'caja')],
                ['Cargos y permisos', 'bi-briefcase', 'cargos.index', ['cargos.*'], $can('cargos.view')],
                ['Mi suscripción', 'bi-patch-check', 'subscription.show', ['subscription.show'], $tenant],
            ],
            'plataforma' => [
                ['Clínicas', 'bi-hospital', 'companies.index', ['companies.*'], $super],
                ['Planes', 'bi-box-seam', 'plans.index', ['plans.*'], $super],
                ['Suscripciones', 'bi-credit-card-2-front', 'subscriptions.index', ['subscriptions.*'], $super],
                ['Usuarios', 'bi-person-gear', 'users.index', ['users.*'], $super],
                ['Roles', 'bi-shield-lock', 'roles.index', ['roles.*'], $super],
                ['Configuración global', 'bi-sliders', 'global-settings.edit', ['global-settings.*'], $super],
            ],
        ];

        $sections = [];
        foreach (self::SECTIONS as $key => [$label, $icon, $color]) {
            $links = [];
            foreach ($items[$key] as [$text, $linkIcon, $route, $patterns, $allowed]) {
                if ($allowed) {
                    $links[] = ['label' => $text, 'icon' => $linkIcon, 'url' => route($route), 'active' => request()->routeIs(...$patterns)];
                }
            }

            if ($links !== []) {
                $sections[] = [
                    'key' => $key,
                    'label' => $label,
                    'icon' => $icon,
                    'color' => $color,
                    'active' => in_array(true, array_column($links, 'active'), true),
                    'items' => $links,
                ];
            }
        }

        return $sections;
    }
}
