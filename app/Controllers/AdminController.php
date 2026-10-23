<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Admin\AdminService;
use App\Core\Http\RequestValidator;
use App\Core\Http\ResponseFormatter;
use App\Core\Http\ErrorHandler;

/**
 * Controlador de Administración
 *
 * Panel de administración: resumen del dashboard, calificaciones,
 * rendimiento de mecánicos, usuarios, vehículos y solicitudes de servicio.
 */
class AdminController extends Controller
{
    private AdminService $adminService;

    public function __construct()
    {
        $this->adminService = new AdminService();
    }

    /**
     * GET /api/admin/dashboard
     */
    public function dashboard(Request $request): Response
    {
        try {
            return ResponseFormatter::success(
                ['summary' => $this->adminService->dashboard()],
                'Resumen del dashboard obtenido correctamente',
                200
            );
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * GET /api/admin/ratings
     */
    public function ratings(Request $request): Response
    {
        try {
            $filters = [
                'mechanicId' => $request->query('mechanic_id'),
                'customerId' => $request->query('customer_id'),
                'minRating'  => $request->query('min_rating'),
                'dateFrom'   => $request->query('date_from'),
                'dateTo'     => $request->query('date_to'),
            ];

            $pagination = RequestValidator::parsePagination($request);
            $result = $this->adminService->ratings($filters, $pagination['perPage'], $pagination['offset']);

            return $this->paginated('ratings', $result, $pagination, 'Calificaciones obtenidas correctamente');
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * GET /api/admin/ratings/mechanics
     */
    public function mechanicPerformance(Request $request): Response
    {
        try {
            return ResponseFormatter::success(
                ['mechanics' => $this->adminService->mechanicPerformance()],
                'Rendimiento de mecánicos obtenido correctamente',
                200
            );
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * GET /api/admin/users
     */
    public function users(Request $request): Response
    {
        try {
            $filters = [
                'role'   => $request->query('role'),
                'status' => $request->query('status'),
                'search' => $request->query('search'),
            ];

            $pagination = RequestValidator::parsePagination($request);
            $result = $this->adminService->users($filters, $pagination['perPage'], $pagination['offset']);

            return $this->paginated('users', $result, $pagination, 'Usuarios obtenidos correctamente');
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * PUT /api/admin/users/{id}/status
     */
    public function updateUserStatus(Request $request, int $id): Response
    {
        try {
            $jsonValidation = RequestValidator::parseJsonBody($request);
            if (!$jsonValidation['valid']) {
                return ResponseFormatter::error($jsonValidation['error'], null, $jsonValidation['statusCode']);
            }

            $status = $request->input('status');
            if (!is_string($status) || !in_array($status, AdminService::USER_STATUSES, true)) {
                return ResponseFormatter::validationError([
                    'status' => 'Estado requerido. Valores válidos: ' . implode(', ', AdminService::USER_STATUSES),
                ]);
            }

            $this->adminService->updateUserStatus($id, $status, (int)$request->getAttribute('userId'));

            return ResponseFormatter::success(null, 'Estado del usuario actualizado correctamente', 200);
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * GET /api/admin/vehicles
     */
    public function vehicles(Request $request): Response
    {
        try {
            $pagination = RequestValidator::parsePagination($request);
            $result = $this->adminService->vehicles($request->query('search'), $pagination['perPage'], $pagination['offset']);

            return $this->paginated('vehicles', $result, $pagination, 'Vehículos obtenidos correctamente');
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * GET /api/admin/service-requests
     */
    public function serviceRequests(Request $request): Response
    {
        try {
            $filters = [
                'status' => $request->query('status'),
                'search' => $request->query('search'),
            ];

            $pagination = RequestValidator::parsePagination($request);
            $result = $this->adminService->serviceRequests($filters, $pagination['perPage'], $pagination['offset']);

            return $this->paginated('serviceRequests', $result, $pagination, 'Solicitudes obtenidas correctamente');
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Respuesta estándar de un listado paginado.
     */
    private function paginated(string $key, array $result, array $pagination, string $message): Response
    {
        return ResponseFormatter::success([
            $key         => $result['items'],
            'count'      => count($result['items']),
            'total'      => $result['total'],
            'page'       => $pagination['page'],
            'perPage'    => $pagination['perPage'],
            'totalPages' => (int)ceil($result['total'] / $pagination['perPage']),
        ], $message, 200);
    }
}
