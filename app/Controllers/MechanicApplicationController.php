<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\MechanicApplication\MechanicApplicationService;
use App\Models\MechanicApplication\MechanicApplicationValidator;
use App\Models\Upload\UploadService;
use App\Core\Http\RequestValidator;
use App\Core\Http\ResponseFormatter;
use App\Core\Http\ErrorHandler;
use App\Core\Http\RateLimiter;
use App\Core\Http\IPValidator;

/**
 * Controlador de Solicitudes de Mecánico
 *
 * Maneja las peticiones HTTP del flujo de solicitud + revisión administrativa:
 * el rol `mechanic` nunca se concede en el registro, solo al aprobar una
 * solicitud.
 */
class MechanicApplicationController extends Controller
{
    private MechanicApplicationService $service;

    public function __construct()
    {
        $this->service = new MechanicApplicationService();
    }

    /**
     * Crear una solicitud para convertirse en mecánico
     *
     * POST /api/mechanic-applications
     */
    public function store(Request $request): Response
    {
        try {
            $ipAddress = IPValidator::getClientIP($request);

            // Limitar tasa por IP para evitar creación masiva de solicitudes
            $rateLimitCheck = RateLimiter::check('mechanic-application', $ipAddress);
            if (!$rateLimitCheck['allowed']) {
                $retryAfter = $rateLimitCheck['reset_at'] - time();
                return ResponseFormatter::rateLimitExceeded($retryAfter);
            }

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

            $validation = MechanicApplicationValidator::validateCreateRequest($request);
            if (!$validation['valid']) {
                return ResponseFormatter::validationError($validation['errors']);
            }

            // El user_id SIEMPRE sale de la sesión autenticada (atributo puesto
            // por AuthMiddleware) — nunca se lee del body de la petición.
            $userId = $request->getAttribute('userId');
            $applicationId = $this->service->create($userId, MechanicApplicationValidator::extractData($request));

            RateLimiter::recordAttempt('mechanic-application', $ipAddress);

            $application = $this->service->getByIdForUser($applicationId, $userId);

            return ResponseFormatter::success(
                ['application' => $application],
                'Solicitud enviada correctamente',
                201
            );

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Obtener el historial de solicitudes del usuario autenticado
     *
     * GET /api/mechanic-applications/me
     */
    public function myApplications(Request $request): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $applications = $this->service->getByUser($userId);

            return ResponseFormatter::success([
                'applications' => $applications,
                'count'        => count($applications),
            ], 'Solicitudes obtenidas correctamente', 200);

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Cancelar una solicitud propia pendiente
     *
     * POST /api/mechanic-applications/{id}/cancel
     */
    public function cancel(Request $request, int $id): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $application = $this->service->cancel($id, $userId);

            return ResponseFormatter::success(
                ['application' => $application],
                'Solicitud cancelada correctamente',
                200
            );

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Adjuntar el documento de certificación a una solicitud propia pendiente
     *
     * POST /api/mechanic-applications/{id}/document
     */
    public function attachDocument(Request $request, int $id): Response
    {
        try {
            $jsonValidation = RequestValidator::parseJsonBody($request);
            if (!$jsonValidation['valid']) {
                return ResponseFormatter::error($jsonValidation['error'], null, $jsonValidation['statusCode']);
            }

            $url = (string)($request->input('certification_document_url') ?? '');
            if ($url === '' || mb_strlen($url) > 500 || !UploadService::isUploadUrl($url)) {
                return ResponseFormatter::validationError([
                    'certification_document_url' => 'Sube primero el documento con POST /api/uploads',
                ]);
            }

            $application = $this->service->attachDocument($id, (int)$request->getAttribute('userId'), $url);

            return ResponseFormatter::success(['application' => $application], 'Documento adjuntado correctamente', 200);
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Listar todas las solicitudes de mecánico con filtro opcional de estado
     * (administrador)
     *
     * GET /api/admin/mechanic-applications
     */
    public function adminIndex(Request $request): Response
    {
        try {
            $filters = [
                'status' => $request->query('status'),
            ];

            $pagination = RequestValidator::parsePagination($request);
            $result = $this->service->adminList($filters, $pagination['perPage'], $pagination['offset']);

            return ResponseFormatter::success([
                'applications' => $result['items'],
                'count'        => count($result['items']),
                'total'        => $result['total'],
                'page'         => $pagination['page'],
                'perPage'      => $pagination['perPage'],
                'totalPages'   => (int)ceil($result['total'] / $pagination['perPage']),
            ], 'Solicitudes obtenidas correctamente', 200);

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Aprobar una solicitud pendiente (administrador)
     *
     * POST /api/admin/mechanic-applications/{id}/approve
     *
     * Sin body — el único dato relevante es el {id} de la ruta. El rol que se
     * concede se determina internamente en MechanicApplicationService, nunca
     * desde la petición.
     */
    public function approve(Request $request, int $id): Response
    {
        try {
            // adminUserId SIEMPRE sale de la sesión autenticada — nunca del body.
            $adminUserId = $request->getAttribute('userId');
            $application = $this->service->approve($id, $adminUserId);

            return ResponseFormatter::success(
                ['application' => $application],
                'Solicitud aprobada correctamente',
                200
            );

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Rechazar una solicitud pendiente (administrador)
     *
     * POST /api/admin/mechanic-applications/{id}/reject
     */
    public function reject(Request $request, int $id): Response
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

            $validation = MechanicApplicationValidator::validateRejectRequest($request);
            if (!$validation['valid']) {
                return ResponseFormatter::validationError($validation['errors']);
            }

            $adminUserId = $request->getAttribute('userId');
            $reason = RequestValidator::sanitizeString($request->input('rejection_reason'));

            $application = $this->service->reject($id, $adminUserId, $reason);

            return ResponseFormatter::success(
                ['application' => $application],
                'Solicitud rechazada correctamente',
                200
            );

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }
}
