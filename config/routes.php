<?php

/**
 * Rutas de la API de P.A.R.C.E
 *
 * Todas las rutas viven bajo /api. El frontend (React) lo sirve el servidor
 * web como archivos estáticos; ver docker/apache.conf y public/router.php.
 */

use App\Controllers\AdminController;
use App\Controllers\Auth\AuthController;
use App\Controllers\ChatbotController;
use App\Controllers\HealthController;
use App\Controllers\InventoryController;
use App\Controllers\MechanicApplicationController;
use App\Controllers\PaymentController;
use App\Controllers\PQRController;
use App\Controllers\ServiceRequestController;
use App\Controllers\SurveyController;
use App\Controllers\UploadController;
use App\Controllers\VehicleController;
use App\Middleware\AuthMiddleware;
use App\Middleware\RBACMiddleware;

$router = $app->getRouter();

// Middleware global: CORS primero, para responder los preflight
$router->middleware([
    \App\Middleware\CORSMiddleware::class,
    \App\Middleware\SecurityHeadersMiddleware::class,
    \App\Middleware\RequestLoggerMiddleware::class,
]);

// Grupos de middleware por tipo de usuario
$auth     = [AuthMiddleware::class];
$customer = [AuthMiddleware::class, [RBACMiddleware::class, ['customer']]];
$mechanic = [AuthMiddleware::class, [RBACMiddleware::class, ['mechanic']]];
$people   = [AuthMiddleware::class, [RBACMiddleware::class, ['customer', 'mechanic']]];
$staff    = [AuthMiddleware::class, [RBACMiddleware::class, ['mechanic', 'administrator', 'super_admin']]];
$admin    = [AuthMiddleware::class, [RBACMiddleware::class, ['administrator', 'super_admin']]];

// ── Salud ───────────────────────────────────────────────────────────────────
$router->get('/api/health', [HealthController::class, 'index']);
$router->get('/api/health/database', [HealthController::class, 'database']);
$router->get('/api/health/system', [HealthController::class, 'system']);

// ── Autenticación ───────────────────────────────────────────────────────────
$router->get('/api/auth/health', [AuthController::class, 'health']);
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/forgot-password', [AuthController::class, 'forgotPassword']);
$router->post('/api/auth/reset-password', [AuthController::class, 'resetPassword']);
$router->post('/api/auth/logout', [AuthController::class, 'logout'])->middleware($auth);
$router->get('/api/auth/me', [AuthController::class, 'me'])->middleware($auth);
$router->put('/api/auth/profile', [AuthController::class, 'profile'])->middleware($auth);
$router->put('/api/auth/password', [AuthController::class, 'changePassword'])->middleware($auth);

// ── Archivos ────────────────────────────────────────────────────────────────
$router->post('/api/uploads', [UploadController::class, 'store'])->middleware($auth);

// ── Catálogo de servicios y asistente ───────────────────────────────────────
$router->get('/api/service-types', [InventoryController::class, 'serviceTypes']);
$router->post('/api/chatbot', [ChatbotController::class, 'reply'])->middleware($auth);

// ── Vehículos ───────────────────────────────────────────────────────────────
$router->get('/api/vehicles', [VehicleController::class, 'index'])->middleware($auth);
$router->post('/api/vehicles', [VehicleController::class, 'store'])->middleware($auth);
$router->get('/api/vehicles/{id}', [VehicleController::class, 'show'])->middleware($auth);
$router->put('/api/vehicles/{id}', [VehicleController::class, 'update'])->middleware($auth);
$router->delete('/api/vehicles/{id}', [VehicleController::class, 'destroy'])->middleware($auth);
$router->put('/api/vehicles/{id}/primary', [VehicleController::class, 'setPrimary'])->middleware($auth);

// ── Solicitudes de servicio: cliente ────────────────────────────────────────
$router->get('/api/service-requests', [ServiceRequestController::class, 'index'])->middleware($customer);
$router->post('/api/service-requests', [ServiceRequestController::class, 'store'])->middleware($customer);
$router->get('/api/service-requests/{id}', [ServiceRequestController::class, 'show'])->middleware($customer);
$router->put('/api/service-requests/{id}', [ServiceRequestController::class, 'update'])->middleware($customer);
$router->post('/api/service-requests/{id}/cancel', [ServiceRequestController::class, 'cancel'])->middleware($customer);
$router->post('/api/service-requests/{id}/rate', [ServiceRequestController::class, 'rate'])->middleware($customer);
$router->get('/api/service-requests/{id}/evidences', [ServiceRequestController::class, 'getEvidences'])->middleware($customer);

// ── Pagos ───────────────────────────────────────────────────────────────────
$router->get('/api/payments', [PaymentController::class, 'index'])->middleware($auth);
$router->get('/api/service-requests/{id}/payment', [PaymentController::class, 'show'])->middleware($auth);
$router->post('/api/service-requests/{id}/payment', [PaymentController::class, 'store'])->middleware($customer);
$router->post('/api/service-requests/{id}/payment/confirm', [PaymentController::class, 'confirm'])->middleware($staff);
$router->post('/api/service-requests/{id}/payment/reject', [PaymentController::class, 'reject'])->middleware($staff);

// ── Solicitudes de servicio: mecánico ───────────────────────────────────────
// '/available' y '/stats' se registran antes de '/{id}': el router compara en
// orden de registro y '{id}' capturaría esas palabras como si fueran un ID.
$router->get('/api/mechanic/requests', [ServiceRequestController::class, 'mechanicIndex'])->middleware($mechanic);
$router->get('/api/mechanic/requests/available', [ServiceRequestController::class, 'availableForMechanic'])->middleware($mechanic);
$router->get('/api/mechanic/stats', [ServiceRequestController::class, 'mechanicStats'])->middleware($mechanic);
$router->get('/api/mechanic/requests/{id}', [ServiceRequestController::class, 'show'])->middleware($mechanic);
$router->post('/api/mechanic/requests/{id}/accept', [ServiceRequestController::class, 'accept'])->middleware($mechanic);
$router->put('/api/mechanic/requests/{id}/start', [ServiceRequestController::class, 'start'])->middleware($mechanic);
$router->put('/api/mechanic/requests/{id}/complete', [ServiceRequestController::class, 'complete'])->middleware($mechanic);
$router->post('/api/mechanic/requests/{id}/rate-customer', [ServiceRequestController::class, 'rateCustomer'])->middleware($mechanic);
$router->post('/api/mechanic/requests/{id}/evidence', [ServiceRequestController::class, 'addEvidence'])->middleware($mechanic);
$router->get('/api/mechanic/requests/{id}/evidences', [ServiceRequestController::class, 'getEvidences'])->middleware($mechanic);
$router->get('/api/mechanic/requests/{id}/parts', [InventoryController::class, 'requestParts'])->middleware($mechanic);
$router->post('/api/mechanic/requests/{id}/parts', [InventoryController::class, 'addRequestPart'])->middleware($mechanic);
$router->delete('/api/mechanic/requests/{id}/parts/{productId}', [InventoryController::class, 'removeRequestPart'])->middleware($mechanic);

// ── Solicitud para ser mecánico ─────────────────────────────────────────────
$router->post('/api/mechanic-applications', [MechanicApplicationController::class, 'store'])->middleware($auth);
$router->get('/api/mechanic-applications/me', [MechanicApplicationController::class, 'myApplications'])->middleware($auth);
$router->post('/api/mechanic-applications/{id}/cancel', [MechanicApplicationController::class, 'cancel'])->middleware($auth);
$router->post('/api/mechanic-applications/{id}/document', [MechanicApplicationController::class, 'attachDocument'])->middleware($auth);

// ── PQR y encuestas ─────────────────────────────────────────────────────────
$router->get('/api/pqr', [PQRController::class, 'index'])->middleware($people);
$router->post('/api/pqr', [PQRController::class, 'store'])->middleware($people);
$router->get('/api/pqr/{id}', [PQRController::class, 'show'])->middleware($people);
$router->post('/api/surveys', [SurveyController::class, 'store'])->middleware($customer);
$router->get('/api/surveys', [SurveyController::class, 'index'])->middleware($customer);

// ── Inventario (consulta para mecánicos, gestión para administradores) ──────
$router->get('/api/inventory/categories', [InventoryController::class, 'categories'])->middleware($staff);
$router->get('/api/inventory/products', [InventoryController::class, 'products'])->middleware($staff);
$router->post('/api/inventory/categories', [InventoryController::class, 'storeCategory'])->middleware($admin);
$router->post('/api/inventory/products', [InventoryController::class, 'storeProduct'])->middleware($admin);
$router->put('/api/inventory/products/{id}', [InventoryController::class, 'updateProduct'])->middleware($admin);
$router->get('/api/inventory/suppliers', [InventoryController::class, 'suppliers'])->middleware($admin);
$router->post('/api/inventory/suppliers', [InventoryController::class, 'storeSupplier'])->middleware($admin);
$router->put('/api/inventory/suppliers/{id}', [InventoryController::class, 'updateSupplier'])->middleware($admin);
$router->get('/api/inventory/receipts', [InventoryController::class, 'receipts'])->middleware($admin);
$router->post('/api/inventory/receipts', [InventoryController::class, 'storeReceipt'])->middleware($admin);
$router->get('/api/inventory/receipts/{id}', [InventoryController::class, 'showReceipt'])->middleware($admin);
$router->delete('/api/inventory/receipts/{id}', [InventoryController::class, 'destroyReceipt'])->middleware($admin);

// ── Administración ──────────────────────────────────────────────────────────
$router->get('/api/admin/dashboard', [AdminController::class, 'dashboard'])->middleware($admin);
$router->get('/api/admin/users', [AdminController::class, 'users'])->middleware($admin);
$router->put('/api/admin/users/{id}/status', [AdminController::class, 'updateUserStatus'])->middleware($admin);
$router->get('/api/admin/vehicles', [AdminController::class, 'vehicles'])->middleware($admin);
$router->get('/api/admin/service-requests', [AdminController::class, 'serviceRequests'])->middleware($admin);
$router->get('/api/admin/ratings', [AdminController::class, 'ratings'])->middleware($admin);
$router->get('/api/admin/ratings/mechanics', [AdminController::class, 'mechanicPerformance'])->middleware($admin);
$router->get('/api/admin/pqr', [PQRController::class, 'adminIndex'])->middleware($admin);
$router->put('/api/admin/pqr/{id}/status', [PQRController::class, 'adminUpdateStatus'])->middleware($admin);
$router->post('/api/admin/pqr/{id}/respond', [PQRController::class, 'adminRespond'])->middleware($admin);
$router->get('/api/admin/surveys', [SurveyController::class, 'adminIndex'])->middleware($admin);
$router->get('/api/admin/mechanic-applications', [MechanicApplicationController::class, 'adminIndex'])->middleware($admin);
$router->post('/api/admin/mechanic-applications/{id}/approve', [MechanicApplicationController::class, 'approve'])->middleware($admin);
$router->post('/api/admin/mechanic-applications/{id}/reject', [MechanicApplicationController::class, 'reject'])->middleware($admin);
