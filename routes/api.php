<?php

use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\ProcedureController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — clientes externos (app móvil, SPA). ARQUITECTURA §11
|--------------------------------------------------------------------------
| Headers: Authorization: Bearer <token> · X-Company-Id: <id> · Accept: application/json
| Capas, en orden: auth:sanctum → api.tenant → api.subscription → api.plan → api.permission
| Errores con `code`: company_required (409), company_forbidden, permission_denied,
| plan_module_missing, subscription_inactive, subscription_grace_readonly (403).
|
| Plantilla para los módulos clínicos:
|   Route::middleware(['api.tenant', 'api.subscription'])->group(function () {
|       Route::middleware('api.plan:agenda')->group(function () {
|           Route::get('/appointments', …)->middleware('api.permission:appointments.view');
|       });
|   });
*/

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

    Route::middleware('auth:sanctum')->group(function () {
        // Sin clínica obligatoria: el cliente primero sabe quién es y dónde puede entrar.
        Route::middleware('api.tenant:optional')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/auth/logout', [AuthController::class, 'logout']);
        });

        Route::middleware(['api.tenant', 'api.subscription'])->group(function () {
            Route::get('/procedures', [ProcedureController::class, 'index'])->middleware('api.permission:procedures.view');

            // Agenda — módulo `agenda`
            Route::middleware('api.plan:agenda')->group(function () {
                Route::get('/appointments', [AppointmentController::class, 'index'])->middleware('api.permission:appointments.view');
                Route::get('/appointments/slots', [AppointmentController::class, 'slots'])->middleware('api.permission:appointments.view');
                Route::post('/appointments', [AppointmentController::class, 'store'])->middleware('api.permission:appointments.create');
                Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])->middleware('api.permission:appointments.view');
                Route::put('/appointments/{appointment}', [AppointmentController::class, 'update'])->middleware('api.permission:appointments.edit');
                Route::patch('/appointments/{appointment}/status', [AppointmentController::class, 'status'])->middleware('api.permission:appointments.edit');
            });

            // Pacientes: dato maestro, sin módulo de plan.
            Route::get('/patients/options', [PatientController::class, 'options'])->middleware('api.permission:patients.view');
            Route::get('/patients', [PatientController::class, 'index'])->middleware('api.permission:patients.view');
            Route::post('/patients', [PatientController::class, 'store'])->middleware('api.permission:patients.create');
            Route::get('/patients/{patient}', [PatientController::class, 'show'])->middleware('api.permission:patients.view');
            Route::put('/patients/{patient}', [PatientController::class, 'update'])->middleware('api.permission:patients.edit');

            // Finanzas (ESTRUCTURA_CUENTAS_Y_GASTOS §7): gastos con caja o finanzas;
            // las cuentas de la tesorería, solo con finanzas.
            Route::prefix('finance')->group(function () {
                Route::middleware('api.plan:caja,finanzas')->group(function () {
                    Route::get('/options', [ExpenseController::class, 'options'])->middleware('api.permission:expenses.create');
                    Route::get('/expenses', [ExpenseController::class, 'list'])->middleware('api.permission:expenses.view');
                    Route::post('/expenses', [ExpenseController::class, 'register'])->middleware('api.permission:expenses.create');
                    Route::get('/expenses/{expense}', [ExpenseController::class, 'detail'])->middleware('api.permission:expenses.view');
                    Route::patch('/expenses/{expense}/cancel', [ExpenseController::class, 'annul'])->middleware('api.permission:expenses.delete');
                    Route::get('/basics', [ExpenseController::class, 'basics'])->middleware('api.permission:expenses.view');
                    Route::get('/expense-categories', [ExpenseController::class, 'categories'])->middleware('api.permission:expense_categories.view,expenses.view');
                    Route::post('/expense-categories', [ExpenseController::class, 'addCategory'])->middleware('api.permission:expense_categories.create');
                    Route::put('/expense-categories/{category}', [ExpenseController::class, 'changeCategory'])->middleware('api.permission:expense_categories.edit');
                });

                Route::middleware('api.plan:finanzas')->group(function () {
                    Route::get('/accounts', [FinanceController::class, 'accounts'])->middleware('api.permission:accounts.view');
                    Route::post('/accounts', [FinanceController::class, 'addAccount'])->middleware('api.permission:accounts.create');
                    Route::put('/accounts/{account}', [FinanceController::class, 'changeAccount'])->middleware('api.permission:accounts.edit');
                    Route::get('/accounts/{account}/movements', [FinanceController::class, 'statement'])->middleware('api.permission:accounts.view');
                    Route::post('/accounts/{account}/movements', [FinanceController::class, 'addMovement'])->middleware('api.permission:account_movements.create');
                    Route::post('/accounts/{account}/transfer', [FinanceController::class, 'addTransfer'])->middleware('api.permission:account_movements.create');
                    Route::patch('/movements/{movement}/cancel', [FinanceController::class, 'annul'])->middleware('api.permission:account_movements.delete');
                });
            });
        });
    });
});
