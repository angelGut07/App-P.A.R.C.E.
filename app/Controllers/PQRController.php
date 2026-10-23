<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\PQR\PQRService;
use App\Models\PQR\PQRValidator;
use App\Core\Http\RequestValidator;
use App\Core\Http\ResponseFormatter;
use App\Core\Http\ErrorHandler;

/**
 * Controlador de PQR
 *
 * Maneja las peticiones HTTP para el módulo de Peticiones, Quejas, Reclamos y
 * Sugerencias: creación y consulta por parte del usuario, y gestión
 * (listar con filtros, cambiar estado, responder) por parte del administrador.
 */
class PQRController extends Controller
{
    private PQRService $pqrService;

    public function __construct()
    {
        $this->pqrService = new PQRService();
    }

    /**
     * Listar los tickets PQR del usuario autenticado
     *
     * GET /api/pqr
     */
    public function index(Request $request): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $status = $request->query('status');

            $tickets = $this->pqrService->getUserPqrs($userId, $status);

            return ResponseFormatter::success([
                'pqr'   => $tickets,
                'count' => count($tickets),
            ], 'Tickets PQR obtenidos correctamente', 200);

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Crear un nuevo ticket PQR
     *
     * POST /api/pqr
     */
    public function store(Request $request): Response
    {
        try {
            $contentTypeValidation = RequestValidator::validateContentType($request, 'POST');
            if (!$contentTypeValidation['valid']) {
                return ResponseFormatter::error(
                    $contentTypeValidation['error'], null, $contentTypeValidation['statusCode']
                );
            }

            $jsonValidation = RequestValidator::parseJsonBody($request);
            if (!$jsonValidation['valid']) {
                return ResponseFormatter::error(
                    $jsonValidation['error'], null, $jsonValidation['statusCode']
                );
            }

            $validation = PQRValidator::validateCreateRequest($request);
            if (!$validation['valid']) {
                return ResponseFormatter::validationError($validation['errors']);
            }

            $userId = $request->getAttribute('userId');

            $data = [
                'type'        => $request->input('type'),
                'subject'     => RequestValidator::sanitizeString($request->input('subject')),
                'description' => RequestValidator::sanitizeString($request->input('description')),
            ];

            $ticketId = $this->pqrService->create($userId, $data);
            $ticket   = $this->pqrService->getByIdForUser($ticketId, $userId);

            return ResponseFormatter::success(
                ['pqr' => $ticket],
                'Ticket PQR creado correctamente',
                201
            );

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Obtener el detalle de un ticket PQR del usuario autenticado
     *
     * GET /api/pqr/{id}
     */
    public function show(Request $request, int $id): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $ticket = $this->pqrService->getByIdForUser($id, $userId);

            return ResponseFormatter::success(
                ['pqr' => $ticket],
                'Ticket PQR obtenido correctamente',
                200
            );

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Listar todos los tickets PQR con filtros (administrador)
     *
     * GET /api/admin/pqr
     */
    public function adminIndex(Request $request): Response
    {
        try {
            $filters = [
                'status' => $request->query('status'),
                'type'   => $request->query('type'),
                'q'      => $request->query('q'),
                'reporter' => $request->query('reporter'),
            ];

            $pagination = RequestValidator::parsePagination($request);
            $result = $this->pqrService->adminList($filters, $pagination['perPage'], $pagination['offset']);

            return ResponseFormatter::success([
                'pqr'        => $result['items'],
                'count'      => count($result['items']),
                'total'      => $result['total'],
                'page'       => $pagination['page'],
                'perPage'    => $pagination['perPage'],
                'totalPages' => (int)ceil($result['total'] / $pagination['perPage']),
            ], 'Tickets PQR obtenidos correctamente', 200);

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Cambiar el estado de un ticket PQR (administrador)
     *
     * PUT /api/admin/pqr/{id}/status
     */
    public function adminUpdateStatus(Request $request, int $id): Response
    {
        try {
            $contentTypeValidation = RequestValidator::validateContentType($request, 'PUT');
            if (!$contentTypeValidation['valid']) {
                return ResponseFormatter::error(
                    $contentTypeValidation['error'], null, $contentTypeValidation['statusCode']
                );
            }

            $jsonValidation = RequestValidator::parseJsonBody($request);
            if (!$jsonValidation['valid']) {
                return ResponseFormatter::error(
                    $jsonValidation['error'], null, $jsonValidation['statusCode']
                );
            }

            $validation = PQRValidator::validateStatusUpdateRequest($request);
            if (!$validation['valid']) {
                return ResponseFormatter::validationError($validation['errors']);
            }

            $this->pqrService->getByIdForAdmin($id);

            $this->pqrService->updateStatus($id, $request->input('status'));
            $updated = $this->pqrService->getByIdForAdmin($id);

            return ResponseFormatter::success(
                ['pqr' => $updated],
                'Estado del ticket PQR actualizado correctamente',
                200
            );

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Responder un ticket PQR (administrador)
     *
     * POST /api/admin/pqr/{id}/respond
     */
    public function adminRespond(Request $request, int $id): Response
    {
        try {
            $contentTypeValidation = RequestValidator::validateContentType($request, 'POST');
            if (!$contentTypeValidation['valid']) {
                return ResponseFormatter::error(
                    $contentTypeValidation['error'], null, $contentTypeValidation['statusCode']
                );
            }

            $jsonValidation = RequestValidator::parseJsonBody($request);
            if (!$jsonValidation['valid']) {
                return ResponseFormatter::error(
                    $jsonValidation['error'], null, $jsonValidation['statusCode']
                );
            }

            $validation = PQRValidator::validateRespondRequest($request);
            if (!$validation['valid']) {
                return ResponseFormatter::validationError($validation['errors']);
            }

            $this->pqrService->getByIdForAdmin($id);

            $adminUserId = $request->getAttribute('userId');
            $responseText = RequestValidator::sanitizeString($request->input('admin_response'));

            $this->pqrService->respond($id, $adminUserId, $responseText);
            $updated = $this->pqrService->getByIdForAdmin($id);

            return ResponseFormatter::success(
                ['pqr' => $updated],
                'Ticket PQR respondido correctamente',
                200
            );

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }
}
