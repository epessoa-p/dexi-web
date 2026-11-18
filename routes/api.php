<?php

use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClinicalController;
use App\Http\Controllers\Api\V1\CommissionController;
use App\Http\Controllers\Api\V1\PatientDocumentController;
use App\Http\Controllers\Api\V1\CommunicationController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\LabOrderController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\ProcedureController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SaleController;
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
            Route::put('/me/password', [AuthController::class, 'password'])->middleware('throttle:6,1');
        });

        Route::middleware(['api.tenant', 'api.subscription'])->group(function () {
            // Inicio de la app: cada bloque se filtra por plan y permiso adentro.
            Route::get('/dashboard', DashboardController::class);

            Route::get('/procedures', [ProcedureController::class, 'index'])->middleware('api.permission:procedures.view');

            // Comunicaciones — módulo `recordatorios`: registrar lo enviado por WhatsApp.
            Route::middleware('api.plan:recordatorios')->group(function () {
                Route::get('/communications/queue', [CommunicationController::class, 'queue'])->middleware('api.permission:communications.view');
                Route::post('/communications/send', [CommunicationController::class, 'send'])->middleware('api.permission:communications.create');
                Route::get('/communications/history', [CommunicationController::class, 'history'])->middleware('api.permission:communications.view');
                Route::get('/followups', [CommunicationController::class, 'followups'])->middleware('api.permission:communications.view');
                Route::post('/patients/{patient}/followups', [CommunicationController::class, 'schedule'])->middleware('api.permission:communications.create');
                Route::post('/followups/{followup}/status', [CommunicationController::class, 'closeFollowup'])->middleware('api.permission:communications.create');
            });

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

            // Documentos y fotos del paciente (privados: se descargan con el token).
            Route::get('/patients/{patient}/documents', [PatientDocumentController::class, 'index'])->middleware('api.permission:patient_files.view');
            Route::post('/patients/{patient}/documents', [PatientDocumentController::class, 'store'])->middleware('api.permission:patient_files.create');
            Route::get('/patients/{patient}/documents/{document}/file', [PatientDocumentController::class, 'file'])->middleware('api.permission:patient_files.view');
            Route::delete('/patients/{patient}/documents/{document}', [PatientDocumentController::class, 'destroy'])->middleware('api.permission:patient_files.delete');

            // Atención clínica y odontograma — módulo `historia_clinica`.
            Route::middleware('api.plan:historia_clinica')->group(function () {
                Route::get('/clinical/options', [ClinicalController::class, 'options'])->middleware('api.permission:consultations.view,odontogram.view');
                Route::get('/consultations', [ClinicalController::class, 'index'])->middleware('api.permission:consultations.view');
                Route::post('/consultations', [ClinicalController::class, 'start'])->middleware('api.permission:consultations.create');
                Route::get('/consultations/{consultation}', [ClinicalController::class, 'show'])->middleware('api.permission:consultations.view');
                Route::put('/consultations/{consultation}', [ClinicalController::class, 'notes'])->middleware('api.permission:consultations.edit');
                Route::post('/consultations/{consultation}/diagnoses', [ClinicalController::class, 'addDiagnosis'])->middleware('api.permission:consultations.edit');
                Route::delete('/consultations/{consultation}/diagnoses/{diagnosis}', [ClinicalController::class, 'removeDiagnosis'])->middleware('api.permission:consultations.edit');
                Route::post('/consultations/{consultation}/procedures', [ClinicalController::class, 'addProcedure'])->middleware('api.permission:consultations.edit');
                Route::delete('/consultations/{consultation}/procedures/{item}', [ClinicalController::class, 'removeProcedure'])->middleware('api.permission:consultations.edit');
                Route::post('/consultations/{consultation}/plan-items/{item}', [ClinicalController::class, 'doPlanItem'])->middleware(['api.plan:tratamientos', 'api.permission:consultations.edit']);
                Route::post('/consultations/{consultation}/close', [ClinicalController::class, 'close'])->middleware('api.permission:consultations.edit');
                Route::post('/consultations/{consultation}/addendum', [ClinicalController::class, 'addendum'])->middleware('api.permission:consultations.edit');
                Route::get('/patients/{patient}/consultations', [ClinicalController::class, 'history'])->middleware('api.permission:consultations.view');

                Route::get('/patients/{patient}/odontogram', [ClinicalController::class, 'odontogram'])->middleware('api.permission:odontogram.view');
                Route::post('/patients/{patient}/odontogram', [ClinicalController::class, 'recordOdontogram'])->middleware('api.permission:odontogram.edit');
                Route::post('/patients/{patient}/odontogram/{entry}/resolve', [ClinicalController::class, 'resolveOdontogram'])->middleware('api.permission:odontogram.edit');
                Route::get('/patients/{patient}/odontogram/teeth/{tooth}', [ClinicalController::class, 'tooth'])->middleware('api.permission:odontogram.view');
            });

            // Finanzas (ESTRUCTURA_CUENTAS_Y_GASTOS §7): gastos con caja o finanzas;
            // las cuentas de la tesorería, solo con finanzas.
            Route::prefix('finance')->group(function () {
                Route::middleware('api.plan:caja,finanzas')->group(function () {
                    Route::get('/options', [ExpenseController::class, 'options'])->middleware('api.permission:expenses.create');
                    Route::get('/expenses', [ExpenseController::class, 'list'])->middleware('api.permission:expenses.view');
                    Route::post('/expenses', [ExpenseController::class, 'register'])->middleware('api.permission:expenses.create');
                    Route::get('/expenses/{expense}', [ExpenseController::class, 'detail'])->middleware('api.permission:expenses.view');
                    Route::get('/expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])->middleware('api.permission:expenses.view');
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
            // Ventas y cobros — módulo `ventas`: cobrar lo pendiente, cuentas por cobrar y abonos.
            Route::middleware('api.plan:ventas')->group(function () {
                Route::get('/sales/options', [SaleController::class, 'options'])->middleware('api.permission:sales.create,receivables.create');
                Route::get('/sales', [SaleController::class, 'index'])->middleware('api.permission:sales.view');
                Route::post('/sales', [SaleController::class, 'store'])->middleware('api.permission:sales.create');
                Route::get('/sales/{sale}', [SaleController::class, 'show'])->middleware('api.permission:sales.view');
                Route::post('/sales/{sale}/payments', [SaleController::class, 'payment'])->middleware('api.permission:receivables.create');
                Route::post('/sales/{sale}/cancel', [SaleController::class, 'cancel'])->middleware('api.permission:sales.delete');
                Route::get('/patients/{patient}/pending', [SaleController::class, 'pending'])->middleware('api.permission:sales.create');
                Route::get('/receivables', [SaleController::class, 'receivables'])->middleware('api.permission:receivables.view');
                Route::post('/receivables/{patient}/pay', [SaleController::class, 'payPatient'])->middleware('api.permission:receivables.create');
            });

            // Inventario (plan `inventario`): existencias, escáner, entradas, salidas y conteo.
            Route::middleware('api.plan:inventario')->group(function () {
                Route::get('/products', [InventoryController::class, 'products'])->middleware('api.permission:products.view');
                Route::get('/products/scan/{code}', [InventoryController::class, 'scan'])->middleware('api.permission:products.view');
                Route::get('/products/{product}', [InventoryController::class, 'product'])->middleware('api.permission:products.view');
                Route::get('/stock-movements/options', [InventoryController::class, 'movementOptions'])->middleware('api.permission:inventory.create');
                Route::post('/stock-movements', [InventoryController::class, 'move'])->middleware('api.permission:inventory.create');
            });
            // Compras (plan `compras`): recibir la mercadería que llegó.
            Route::middleware('api.plan:compras')->group(function () {
                Route::get('/purchases/pending', [InventoryController::class, 'pendingPurchases'])->middleware('api.permission:purchases.view');
                Route::get('/purchases/{purchase}', [InventoryController::class, 'purchaseDetail'])->middleware('api.permission:purchases.view');
                Route::post('/purchases/{purchase}/receive', [InventoryController::class, 'receive'])->middleware('api.permission:purchases.edit');
            });

            // Laboratorio (plan `laboratorio`): trabajos en curso, su recorrido y la orden nueva.
            Route::middleware('api.plan:laboratorio')->group(function () {
                Route::get('/lab-orders', [LabOrderController::class, 'list'])->middleware('api.permission:lab_orders.view');
                Route::get('/lab-orders/options', [LabOrderController::class, 'options'])->middleware('api.permission:lab_orders.create');
                Route::post('/lab-orders', [LabOrderController::class, 'register'])->middleware('api.permission:lab_orders.create');
                Route::get('/lab-orders/{order}', [LabOrderController::class, 'detailFor'])->middleware('api.permission:lab_orders.view');
                Route::post('/lab-orders/{order}/status', [LabOrderController::class, 'changeStatus'])->middleware('api.permission:lab_orders.edit');
            });

            // Resumen para el dueño (plan `estadisticas`); el dinero pide reports.finance (se revisa adentro).
            Route::get('/reports/summary', [ReportController::class, 'summaryApi'])
                ->middleware(['api.plan:estadisticas', 'api.permission:reports.finance']);
            // Reportes por sección: cada una revisa adentro su permiso y su módulo (como la web).
            Route::middleware(['api.plan:estadisticas', 'api.permission:reports.view,reports.finance'])->group(function () {
                Route::get('/reports', [ReportController::class, 'sectionsApi']);
                Route::get('/reports/{section}', [ReportController::class, 'sectionApi'])->where('section', '[a-z]+');
            });

            // Comisiones (plan `comisiones`): lo que se debe y pagar una liquidación.
            Route::middleware('api.plan:comisiones')->group(function () {
                // Lo suyo: cada odontólogo ve sus comisiones sin el permiso de ver las de todos.
                Route::get('/me/commissions', [CommissionController::class, 'mineApi']);
                Route::get('/commissions/pending', [CommissionController::class, 'pendingList'])->middleware('api.permission:commissions.view');
                Route::get('/commissions/{personal}', [CommissionController::class, 'detailFor'])->middleware('api.permission:commissions.view');
                Route::post('/commissions/{personal}/settle', [CommissionController::class, 'pay'])->middleware('api.permission:commissions.create');
            });
        });
    });
});
