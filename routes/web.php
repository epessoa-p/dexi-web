<?php

use App\Http\Controllers\Admin\AppointmentController;
use App\Http\Controllers\Admin\AvailabilityController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\CajaController;
use App\Http\Controllers\Admin\CargoController;
use App\Http\Controllers\Admin\CashController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\ConsultationController;
use App\Http\Controllers\Admin\OdontogramController;
use App\Http\Controllers\Admin\ExpenseCategoryController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\FinanceAccountController;
use App\Http\Controllers\Admin\PatientController;
use App\Http\Controllers\Admin\PatientDocumentController;
use App\Http\Controllers\Admin\PaymentMethodController;
use App\Http\Controllers\Admin\ProcedureController;
use App\Http\Controllers\Admin\SpecialtyController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\GlobalSettingsController;
use App\Http\Controllers\Admin\PersonalController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas web
|--------------------------------------------------------------------------
| SetTenant y EnsureSubscriptionActive corren en TODO el grupo `web`
| (bootstrap/app.php). Por ruta se añaden, en este orden:
|
|   plan:<módulo>              → el plan de la clínica incluye el módulo (OR)
|   check-permission:<a,b>     → el usuario tiene alguno de los permisos (OR)
|   tenant                     → exige clínica activa (el superadmin debe "entrar")
|
| Los módulos administrativos (usuarios, cargos, personal, consultorios,
| configuración) no llevan `plan:`: están siempre disponibles. ARQUITECTURA §6.2.
*/

// Invitados
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.store')->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    // Sesión y clínica activa (exentas del bloqueo por suscripción).
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/select-company', [LoginController::class, 'selectCompany'])->name('select-company');
    Route::post('/set-company/{companyId}', [LoginController::class, 'setCompany'])->name('set-company');
    Route::post('/exit-company', [LoginController::class, 'exitCompany'])->name('exit-company');

    // Suscripción de la clínica activa
    Route::get('/mi-suscripcion', [SubscriptionController::class, 'show'])->name('subscription.show')->middleware('tenant');
    Route::get('/suscripcion-vencida', [SubscriptionController::class, 'blocked'])->name('subscription.blocked');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /*
    |----------------------------------------------------------------------
    | Administración de la plataforma (solo superadmin)
    |----------------------------------------------------------------------
    */
    Route::middleware('super-admin')->group(function () {
        Route::prefix('admin/companies')->name('companies.')->group(function () {
            Route::get('/', [CompanyController::class, 'index'])->name('index');
            Route::get('/create', [CompanyController::class, 'create'])->name('create');
            Route::post('/', [CompanyController::class, 'store'])->name('store');
            Route::get('/{company}', [CompanyController::class, 'show'])->name('show');
            Route::get('/{company}/edit', [CompanyController::class, 'edit'])->name('edit');
            Route::put('/{company}', [CompanyController::class, 'update'])->name('update');
            Route::delete('/{company}', [CompanyController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('admin/plans')->name('plans.')->group(function () {
            Route::get('/', [PlanController::class, 'index'])->name('index');
            Route::get('/create', [PlanController::class, 'create'])->name('create');
            Route::post('/', [PlanController::class, 'store'])->name('store');
            Route::get('/{plan}', [PlanController::class, 'show'])->name('show');
            Route::get('/{plan}/edit', [PlanController::class, 'edit'])->name('edit');
            Route::put('/{plan}', [PlanController::class, 'update'])->name('update');
            Route::delete('/{plan}', [PlanController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('admin/subscriptions')->name('subscriptions.')->group(function () {
            Route::get('/', [AdminSubscriptionController::class, 'index'])->name('index');
            Route::get('/create', [AdminSubscriptionController::class, 'create'])->name('create');
            Route::post('/', [AdminSubscriptionController::class, 'store'])->name('store');
            Route::get('/{subscription}', [AdminSubscriptionController::class, 'show'])->name('show');
            Route::get('/{subscription}/edit', [AdminSubscriptionController::class, 'edit'])->name('edit');
            Route::put('/{subscription}', [AdminSubscriptionController::class, 'update'])->name('update');
            Route::post('/{subscription}/renew', [AdminSubscriptionController::class, 'renew'])->name('renew');
            Route::post('/{subscription}/cancel', [AdminSubscriptionController::class, 'cancel'])->name('cancel');
            Route::post('/{subscription}/payments', [AdminSubscriptionController::class, 'storePayment'])->name('payments.store');
        });

        // Roles: catálogo GLOBAL de la plataforma.
        Route::prefix('admin/roles')->name('roles.')->group(function () {
            Route::get('/', [RoleController::class, 'index'])->name('index');
            Route::get('/create', [RoleController::class, 'create'])->name('create');
            Route::post('/', [RoleController::class, 'store'])->name('store');
            Route::get('/{role}', [RoleController::class, 'show'])->name('show');
            Route::get('/{role}/edit', [RoleController::class, 'edit'])->name('edit');
            Route::put('/{role}', [RoleController::class, 'update'])->name('update');
            Route::delete('/{role}', [RoleController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('admin/global-settings')->name('global-settings.')->group(function () {
            Route::get('/', [GlobalSettingsController::class, 'edit'])->name('edit');
            Route::put('/', [GlobalSettingsController::class, 'update'])->name('update');
        });
    });

    /*
    |----------------------------------------------------------------------
    | Módulos administrativos de la clínica (siempre disponibles)
    |----------------------------------------------------------------------
    */

    // Usuarios
    Route::prefix('admin/users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index')->middleware('check-permission:users.view');
        Route::get('/create', [UserController::class, 'create'])->name('create')->middleware('check-permission:users.create');
        Route::post('/', [UserController::class, 'store'])->name('store')->middleware('check-permission:users.create');
        Route::get('/{user}', [UserController::class, 'show'])->name('show')->middleware('check-permission:users.view');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit')->middleware('check-permission:users.edit');
        Route::put('/{user}', [UserController::class, 'update'])->name('update')->middleware('check-permission:users.edit');
        Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy')->middleware('check-permission:users.delete');
        Route::post('/{user}/assign-role/{company}/{role}', [UserController::class, 'assignRole'])->name('assign-role')->middleware('check-permission:users.edit');
    });

    // Cargos
    Route::prefix('admin/cargos')->name('cargos.')->group(function () {
        Route::get('/', [CargoController::class, 'index'])->name('index')->middleware('check-permission:cargos.view');
        Route::get('/create', [CargoController::class, 'create'])->name('create')->middleware('check-permission:cargos.create');
        Route::post('/', [CargoController::class, 'store'])->name('store')->middleware('check-permission:cargos.create');
        Route::get('/role-permissions/{role}', [CargoController::class, 'rolePermissions'])->name('role-permissions')->middleware('check-permission:cargos.create,cargos.edit');
        Route::get('/{cargo}/edit', [CargoController::class, 'edit'])->name('edit')->middleware('check-permission:cargos.edit');
        Route::put('/{cargo}', [CargoController::class, 'update'])->name('update')->middleware('check-permission:cargos.edit');
        Route::delete('/{cargo}', [CargoController::class, 'destroy'])->name('destroy')->middleware('check-permission:cargos.delete');
    });

    // Personal
    Route::prefix('admin/personal')->name('personal.')->group(function () {
        Route::get('/', [PersonalController::class, 'index'])->name('index')->middleware('check-permission:personal.view');
        Route::get('/create', [PersonalController::class, 'create'])->name('create')->middleware('check-permission:personal.create');
        Route::post('/', [PersonalController::class, 'store'])->name('store')->middleware('check-permission:personal.create');
        Route::get('/{personal}/edit', [PersonalController::class, 'edit'])->name('edit')->middleware('check-permission:personal.edit');
        Route::put('/{personal}', [PersonalController::class, 'update'])->name('update')->middleware('check-permission:personal.edit');
        Route::delete('/{personal}/access', [PersonalController::class, 'revokeAccess'])->name('revoke-access')->middleware('check-permission:personal.edit');
        Route::delete('/{personal}', [PersonalController::class, 'destroy'])->name('destroy')->middleware('check-permission:personal.delete');
    });

    // Consultorios (sucursales) — recurso limitado por plan
    Route::prefix('admin/branches')->name('branches.')->group(function () {
        Route::get('/', [BranchController::class, 'index'])->name('index')->middleware('check-permission:branches.view');
        Route::get('/create', [BranchController::class, 'create'])->name('create')->middleware('check-permission:branches.create');
        Route::post('/', [BranchController::class, 'store'])->name('store')->middleware('check-permission:branches.create');
        Route::get('/{branch}', [BranchController::class, 'show'])->name('show')->middleware('check-permission:branches.view');
        Route::get('/{branch}/edit', [BranchController::class, 'edit'])->name('edit')->middleware('check-permission:branches.edit');
        Route::put('/{branch}', [BranchController::class, 'update'])->name('update')->middleware('check-permission:branches.edit');
        Route::delete('/{branch}', [BranchController::class, 'destroy'])->name('destroy')->middleware('check-permission:branches.delete');
    });

    // Pacientes — dato maestro: administrativo (siempre disponible) pero de una sola clínica
    Route::middleware('tenant')->prefix('pacientes')->name('patients.')->group(function () {
        Route::get('/', [PatientController::class, 'index'])->name('index')->middleware('check-permission:patients.view');
        Route::get('/buscar', [PatientController::class, 'search'])->name('search')->middleware('check-permission:patients.view');
        Route::get('/nuevo', [PatientController::class, 'create'])->name('create')->middleware('check-permission:patients.create');
        Route::post('/', [PatientController::class, 'store'])->name('store')->middleware('check-permission:patients.create');
        Route::get('/{patient}', [PatientController::class, 'show'])->name('show')->middleware('check-permission:patients.view');
        Route::get('/{patient}/editar', [PatientController::class, 'edit'])->name('edit')->middleware('check-permission:patients.edit');
        Route::put('/{patient}', [PatientController::class, 'update'])->name('update')->middleware('check-permission:patients.edit');
        Route::delete('/{patient}', [PatientController::class, 'destroy'])->name('destroy')->middleware('check-permission:patients.delete');

        // Documentos y fotos (datos de salud: disco privado, permiso propio)
        Route::post('/{patient}/documentos', [PatientDocumentController::class, 'store'])->name('documents.store')->middleware('check-permission:patient_files.create');
        Route::get('/{patient}/documentos/{document}', [PatientDocumentController::class, 'show'])->name('documents.show')->middleware('check-permission:patient_files.view');
        Route::delete('/{patient}/documentos/{document}', [PatientDocumentController::class, 'destroy'])->name('documents.destroy')->middleware('check-permission:patient_files.delete');
    });

    // Agenda — módulo `agenda`
    Route::middleware(['tenant', 'plan:agenda'])->prefix('agenda')->group(function () {
        Route::name('appointments.')->group(function () {
            Route::get('/', [AppointmentController::class, 'index'])->name('index')->middleware('check-permission:appointments.view');
            Route::get('/eventos', [AppointmentController::class, 'events'])->name('events')->middleware('check-permission:appointments.view');
            Route::get('/horas-libres', [AppointmentController::class, 'slots'])->name('slots')->middleware('check-permission:appointments.view');
            Route::get('/hoy', [AppointmentController::class, 'today'])->name('today')->middleware('check-permission:appointments.view');
            Route::get('/nueva', [AppointmentController::class, 'create'])->name('create')->middleware('check-permission:appointments.create');
            Route::post('/', [AppointmentController::class, 'store'])->name('store')->middleware('check-permission:appointments.create');
            Route::get('/citas/{appointment}', [AppointmentController::class, 'show'])->name('show')->middleware('check-permission:appointments.view');
            Route::get('/citas/{appointment}/editar', [AppointmentController::class, 'edit'])->name('edit')->middleware('check-permission:appointments.edit');
            Route::put('/citas/{appointment}', [AppointmentController::class, 'update'])->name('update')->middleware('check-permission:appointments.edit');
            Route::patch('/citas/{appointment}/mover', [AppointmentController::class, 'move'])->name('move')->middleware('check-permission:appointments.edit');
            Route::post('/citas/{appointment}/estado', [AppointmentController::class, 'status'])->name('status')->middleware('check-permission:appointments.edit');
        });

        Route::prefix('disponibilidad')->name('availability.')->group(function () {
            Route::get('/', [AvailabilityController::class, 'index'])->name('index')->middleware('check-permission:schedules.view');
            Route::put('/{personal}', [AvailabilityController::class, 'update'])->name('update')->middleware('check-permission:schedules.edit');
            Route::post('/bloqueos', [AvailabilityController::class, 'storeBlock'])->name('blocks.store')->middleware('check-permission:schedules.edit');
            Route::delete('/bloqueos/{block}', [AvailabilityController::class, 'destroyBlock'])->name('blocks.destroy')->middleware('check-permission:schedules.edit');
        });
    });

    // Atención clínica y odontograma — módulo `historia_clinica`
    Route::middleware(['tenant', 'plan:historia_clinica'])->group(function () {
        Route::prefix('atencion/consultas')->name('consultations.')->group(function () {
            Route::get('/', [ConsultationController::class, 'index'])->name('index')->middleware('check-permission:consultations.view');
            Route::post('/', [ConsultationController::class, 'start'])->name('start')->middleware('check-permission:consultations.create');
            Route::get('/{consultation}', [ConsultationController::class, 'show'])->name('show')->middleware('check-permission:consultations.view');
            Route::put('/{consultation}/notas', [ConsultationController::class, 'notes'])->name('notes')->middleware('check-permission:consultations.edit');
            Route::put('/{consultation}/profesional', [ConsultationController::class, 'professional'])->name('professional')->middleware('check-permission:consultations.edit');
            Route::post('/{consultation}/diagnosticos', [ConsultationController::class, 'addDiagnosis'])->name('diagnoses.store')->middleware('check-permission:consultations.edit');
            Route::delete('/{consultation}/diagnosticos/{diagnosis}', [ConsultationController::class, 'removeDiagnosis'])->name('diagnoses.destroy')->middleware('check-permission:consultations.edit');
            Route::post('/{consultation}/procedimientos', [ConsultationController::class, 'addProcedure'])->name('procedures.store')->middleware('check-permission:consultations.edit');
            Route::delete('/{consultation}/procedimientos/{item}', [ConsultationController::class, 'removeProcedure'])->name('procedures.destroy')->middleware('check-permission:consultations.edit');
            Route::post('/{consultation}/cerrar', [ConsultationController::class, 'close'])->name('close')->middleware('check-permission:consultations.edit');
            Route::post('/{consultation}/nota', [ConsultationController::class, 'addendum'])->name('addendum')->middleware('check-permission:consultations.edit');
            Route::get('/{consultation}/receta', [ConsultationController::class, 'prescription'])->name('prescription')->middleware('check-permission:consultations.view');
        });

        Route::prefix('pacientes/{patient}/odontograma')->name('odontogram.')->group(function () {
            Route::get('/', [OdontogramController::class, 'state'])->name('state')->middleware('check-permission:odontogram.view');
            Route::get('/pieza/{tooth}', [OdontogramController::class, 'tooth'])->name('tooth')->whereNumber('tooth')->middleware('check-permission:odontogram.view');
            Route::post('/', [OdontogramController::class, 'store'])->name('store')->middleware('check-permission:odontogram.edit');
            Route::post('/{entry}/quitar', [OdontogramController::class, 'resolve'])->name('resolve')->middleware('check-permission:odontogram.edit');
        });
    });

    // Tarifario y especialidades — administrativo (lo usan agenda, odontograma, presupuestos y ventas)
    Route::middleware('tenant')->prefix('configuracion/tarifario')->name('procedures.')->group(function () {
        Route::get('/', [ProcedureController::class, 'index'])->name('index')->middleware('check-permission:procedures.view');
        Route::post('/', [ProcedureController::class, 'store'])->name('store')->middleware('check-permission:procedures.create');
        Route::post('/catalogo-sugerido', [ProcedureController::class, 'loadSuggested'])->name('suggested')->middleware('check-permission:procedures.create');
        Route::post('/ajustar-precios', [ProcedureController::class, 'adjustPrices'])->name('adjust')->middleware('check-permission:procedures.edit');
        Route::put('/{procedure}', [ProcedureController::class, 'update'])->name('update')->middleware('check-permission:procedures.edit');
        Route::patch('/{procedure}/precio', [ProcedureController::class, 'price'])->name('price')->middleware('check-permission:procedures.edit');
        Route::delete('/{procedure}', [ProcedureController::class, 'destroy'])->name('destroy')->middleware('check-permission:procedures.delete');
    });
    Route::middleware('tenant')->prefix('configuracion/especialidades')->name('specialties.')->group(function () {
        Route::get('/', [SpecialtyController::class, 'index'])->name('index')->middleware('check-permission:procedures.view');
        Route::post('/', [SpecialtyController::class, 'store'])->name('store')->middleware('check-permission:procedures.edit');
        Route::put('/{specialty}', [SpecialtyController::class, 'update'])->name('update')->middleware('check-permission:procedures.edit');
        Route::delete('/{specialty}', [SpecialtyController::class, 'destroy'])->name('destroy')->middleware('check-permission:procedures.edit');
    });

    // Configuración de la clínica
    Route::middleware('tenant')->prefix('configuracion')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'edit'])->name('edit')->middleware('check-permission:settings.view');
        Route::put('/', [SettingsController::class, 'update'])->name('update')->middleware('check-permission:settings.edit');
    });

    /*
    |----------------------------------------------------------------------
    | Módulos que dependen del plan
    |----------------------------------------------------------------------
    | Plantilla para el dominio dental (agenda, historia clínica, …):
    |   Route::middleware(['tenant', 'plan:agenda'])->prefix('agenda')->name('appointments.')->group(…)
    */

    // Cajas — módulo `caja`
    Route::middleware(['tenant', 'plan:caja'])->prefix('admin/cajas')->name('cajas.')->group(function () {
        Route::get('/', [CajaController::class, 'index'])->name('index')->middleware('check-permission:cajas.view');
        Route::get('/create', [CajaController::class, 'create'])->name('create')->middleware('check-permission:cajas.create');
        Route::post('/', [CajaController::class, 'store'])->name('store')->middleware('check-permission:cajas.create');
        Route::get('/{caja}/edit', [CajaController::class, 'edit'])->name('edit')->middleware('check-permission:cajas.edit');
        Route::put('/{caja}', [CajaController::class, 'update'])->name('update')->middleware('check-permission:cajas.edit');
        Route::delete('/{caja}', [CajaController::class, 'destroy'])->name('destroy')->middleware('check-permission:cajas.delete');
    });

    // Caja por turnos: apertura, movimientos, arqueo y cierre — módulo `caja`
    Route::middleware(['tenant', 'plan:caja'])->prefix('caja')->name('cash.')->group(function () {
        Route::get('/', [CashController::class, 'index'])->name('index')->middleware('check-permission:cash.view');
        Route::post('/{caja}/abrir', [CashController::class, 'open'])->name('open')->middleware('check-permission:cash.open');
        Route::get('/turnos/{session}', [CashController::class, 'show'])->name('show')->middleware('check-permission:cash.view');
        Route::post('/turnos/{session}/movimientos', [CashController::class, 'movement'])->name('movement')->middleware('check-permission:cash.movements');
        Route::post('/turnos/{session}/cerrar', [CashController::class, 'close'])->name('close')->middleware('check-permission:cash.close');
    });

    // Métodos de pago — con caja o ventas
    Route::middleware(['tenant', 'plan:caja,ventas'])->prefix('configuracion/metodos-de-pago')->name('payment-methods.')->group(function () {
        Route::get('/', [PaymentMethodController::class, 'index'])->name('index')->middleware('check-permission:payment_methods.view');
        Route::post('/', [PaymentMethodController::class, 'store'])->name('store')->middleware('check-permission:payment_methods.create');
        Route::put('/{method}', [PaymentMethodController::class, 'update'])->name('update')->middleware('check-permission:payment_methods.edit');
    });

    /*
    | Finanzas (ESTRUCTURA_CUENTAS_Y_GASTOS.md): los gastos salen de una cuenta
    | (finanzas) o de la caja, así que basta con uno de los dos módulos. Las
    | cuentas de la tesorería, solo con finanzas.
    */
    Route::middleware(['tenant', 'plan:caja,finanzas'])->group(function () {
        Route::prefix('finanzas/gastos')->name('expenses.')->group(function () {
            Route::get('/', [ExpenseController::class, 'index'])->name('index')->middleware('check-permission:expenses.view');
            Route::get('/nuevo', [ExpenseController::class, 'create'])->name('create')->middleware('check-permission:expenses.create');
            Route::post('/', [ExpenseController::class, 'store'])->name('store')->middleware('check-permission:expenses.create');
            Route::get('/{expense}', [ExpenseController::class, 'show'])->name('show')->middleware('check-permission:expenses.view');
            Route::get('/{expense}/comprobante', [ExpenseController::class, 'receipt'])->name('receipt')->middleware('check-permission:expenses.view');
            // Un gasto no se edita: se anula (el dinero vuelve a donde salió) y se registra de nuevo.
            Route::post('/{expense}/anular', [ExpenseController::class, 'cancel'])->name('cancel')->middleware('check-permission:expenses.delete');
        });

        Route::prefix('finanzas/categorias-de-gasto')->name('expense-categories.')->group(function () {
            Route::get('/', [ExpenseCategoryController::class, 'index'])->name('index')->middleware('check-permission:expense_categories.view');
            Route::post('/', [ExpenseCategoryController::class, 'store'])->name('store')->middleware('check-permission:expense_categories.create');
            Route::put('/{category}', [ExpenseCategoryController::class, 'update'])->name('update')->middleware('check-permission:expense_categories.edit');
            Route::delete('/{category}', [ExpenseCategoryController::class, 'destroy'])->name('destroy')->middleware('check-permission:expense_categories.delete');
        });
    });

    Route::middleware(['tenant', 'plan:finanzas'])->prefix('finanzas/cuentas')->name('finance-accounts.')->group(function () {
        Route::get('/', [FinanceAccountController::class, 'index'])->name('index')->middleware('check-permission:accounts.view');
        Route::post('/', [FinanceAccountController::class, 'store'])->name('store')->middleware('check-permission:accounts.create');
        Route::get('/{account}', [FinanceAccountController::class, 'show'])->name('show')->middleware('check-permission:accounts.view');
        Route::put('/{account}', [FinanceAccountController::class, 'update'])->name('update')->middleware('check-permission:accounts.edit');
        Route::delete('/{account}', [FinanceAccountController::class, 'destroy'])->name('destroy')->middleware('check-permission:accounts.delete');
        Route::post('/{account}/movimientos', [FinanceAccountController::class, 'move'])->name('move')->middleware('check-permission:account_movements.create');
        Route::post('/{account}/transferir', [FinanceAccountController::class, 'transfer'])->name('transfer')->middleware('check-permission:account_movements.create');
        Route::post('/movimientos/{movement}/anular', [FinanceAccountController::class, 'cancelMovement'])->name('movements.cancel')->middleware('check-permission:account_movements.delete');
    });

    // Proveedores — los comparten Gastos y (más adelante) Compras e Inventario
    Route::middleware(['tenant', 'plan:finanzas,compras,inventario'])->prefix('proveedores')->name('suppliers.')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])->name('index')->middleware('check-permission:suppliers.view');
        Route::get('/create', [SupplierController::class, 'create'])->name('create')->middleware('check-permission:suppliers.create');
        Route::post('/', [SupplierController::class, 'store'])->name('store')->middleware('check-permission:suppliers.create');
        Route::get('/{supplier}', [SupplierController::class, 'show'])->name('show')->middleware('check-permission:suppliers.view');
        Route::get('/{supplier}/edit', [SupplierController::class, 'edit'])->name('edit')->middleware('check-permission:suppliers.edit');
        Route::put('/{supplier}', [SupplierController::class, 'update'])->name('update')->middleware('check-permission:suppliers.edit');
        Route::delete('/{supplier}', [SupplierController::class, 'destroy'])->name('destroy')->middleware('check-permission:suppliers.delete');
    });
});

Route::redirect('/', '/dashboard');
