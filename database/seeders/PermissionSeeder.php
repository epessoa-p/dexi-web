<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Permisos: slug = modulo.accion, `module` agrupa y se mapea a un módulo de
 * plan en Plan::PERMISSION_MODULE_FEATURES. Ver ARQUITECTURA §5 y §6.2.
 */
class PermissionSeeder extends Seeder
{
    /**
     * Permisos de nivel plataforma: solo el superadmin debería tenerlos.
     */
    public const SAAS_MODULES = \App\Models\Permission::PLATFORM_MODULES;

    public const MODULE_LABELS = [
        'companies' => 'Clínicas',
        'plans' => 'Planes',
        'subscriptions' => 'Suscripciones',
        'roles' => 'Roles',
        'users' => 'Usuarios',
        'cargos' => 'Cargos',
        'personal' => 'Personal',
        'branches' => 'Consultorios',
        'cajas' => 'Cajas',
        'settings' => 'Configuración',
        'patients' => 'Pacientes',
        'procedures' => 'Tarifario',
        'appointments' => 'Citas',
        'consultations' => 'Consultas',
        'odontogram' => 'Odontograma',
        'treatment_plans' => 'Planes de tratamiento',
        'sales' => 'Ventas',
        'receivables' => 'Cuentas por cobrar',
        'communications' => 'Comunicaciones',
        'commissions' => 'Comisiones',
        'products' => 'Productos e insumos',
        'inventory' => 'Movimientos de inventario',
        'purchases' => 'Compras',
        'lab_orders' => 'Órdenes de laboratorio',
        'reports' => 'Reportes',
        'schedules' => 'Horarios y bloqueos',
        'patient_files' => 'Documentos y fotos de pacientes',
        'cash' => 'Caja (turnos)',
        'payment_methods' => 'Métodos de pago',
        'accounts' => 'Cuentas',
        'account_movements' => 'Movimientos de cuenta',
        'expenses' => 'Gastos',
        'expense_categories' => 'Categorías de gasto',
        'suppliers' => 'Proveedores',
    ];

    /** Acciones de cada módulo que no usa el CRUD completo. */
    public const MODULE_ACTIONS = [
        'cash' => ['view' => 'Ver', 'open' => 'Abrir', 'movements' => 'Registrar movimientos de', 'close' => 'Cerrar y arquear'],
        'payment_methods' => ['view' => 'Ver', 'create' => 'Crear', 'edit' => 'Editar'],
        'schedules' => ['view' => 'Ver', 'edit' => 'Editar'],
        'consultations' => ['view' => 'Ver', 'create' => 'Abrir', 'edit' => 'Registrar en'],
        'odontogram' => ['view' => 'Ver', 'edit' => 'Editar'],
        'treatment_plans' => ['view' => 'Ver', 'create' => 'Crear', 'edit' => 'Editar y aprobar'],
        'sales' => ['view' => 'Ver', 'create' => 'Cobrar', 'delete' => 'Anular'],
        'receivables' => ['view' => 'Ver', 'create' => 'Registrar abonos en'],
        'communications' => ['view' => 'Ver', 'create' => 'Enviar mensajes y programar controles en', 'edit' => 'Editar los mensajes de'],
        'commissions' => ['view' => 'Ver', 'create' => 'Pagar liquidaciones de', 'edit' => 'Editar las reglas de', 'delete' => 'Anular liquidaciones de'],
        'inventory' => ['view' => 'Ver', 'create' => 'Registrar entradas, salidas y ajustes de'],
        'purchases' => ['view' => 'Ver', 'create' => 'Registrar', 'edit' => 'Recibir y pagar', 'delete' => 'Anular'],
        'lab_orders' => ['view' => 'Ver', 'create' => 'Enviar', 'edit' => 'Actualizar y pagar'],
        // view = clínicos e inventario (sin dinero) · finance = con dinero · export = descargar Excel.
        'reports' => ['view' => 'Ver', 'finance' => 'Ver cifras de dinero en', 'export' => 'Exportar'],
        'patient_files' => ['view' => 'Ver', 'create' => 'Subir', 'delete' => 'Eliminar'],
        'account_movements' => ['create' => 'Registrar', 'delete' => 'Anular'],
        // Un gasto no se edita: se anula y se vuelve a registrar.
        'expenses' => ['view' => 'Ver', 'create' => 'Registrar', 'delete' => 'Anular'],
    ];

    public function run(): void
    {
        $actions = [
            'view' => 'Ver',
            'create' => 'Crear',
            'edit' => 'Editar',
            'delete' => 'Eliminar',
        ];

        $permissions = [];

        foreach (['companies', 'plans', 'users', 'roles', 'cargos', 'personal', 'branches', 'cajas', 'patients', 'patient_files', 'procedures', 'appointments', 'schedules', 'consultations', 'odontogram', 'treatment_plans', 'sales', 'receivables', 'communications', 'commissions', 'products', 'inventory', 'purchases', 'lab_orders', 'reports',
            'cash', 'payment_methods', 'accounts', 'account_movements', 'expenses', 'expense_categories', 'suppliers'] as $module) {
            foreach (self::MODULE_ACTIONS[$module] ?? $actions as $action => $verb) {
                $permissions[] = [
                    'name' => $verb . ' ' . self::MODULE_LABELS[$module],
                    'slug' => "{$module}.{$action}",
                    'module' => $module,
                ];
            }
        }

        $permissions = array_merge($permissions, [
            ['name' => 'Ver Suscripciones', 'slug' => 'subscriptions.view', 'module' => 'subscriptions'],
            ['name' => 'Crear Suscripciones', 'slug' => 'subscriptions.create', 'module' => 'subscriptions'],
            ['name' => 'Editar Suscripciones', 'slug' => 'subscriptions.edit', 'module' => 'subscriptions'],
            ['name' => 'Registrar Pagos de Suscripción', 'slug' => 'subscriptions.payments', 'module' => 'subscriptions'],

            ['name' => 'Ver Configuración', 'slug' => 'settings.view', 'module' => 'settings'],
            ['name' => 'Editar Configuración', 'slug' => 'settings.edit', 'module' => 'settings'],
        ]);

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(['slug' => $permission['slug']], $permission);
        }
    }
}
