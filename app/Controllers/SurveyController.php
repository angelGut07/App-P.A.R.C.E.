<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Survey\SurveyService;
use App\Models\Survey\SurveyValidator;
use App\Core\Http\RequestValidator;
use App\Core\Http\ResponseFormatter;
use App\Core\Http\ErrorHandler;

/**
 * Controlador de Encuestas
 *
 * Maneja las peticiones HTTP para las encuestas de satisfacción posteriores
 * al servicio: creación y consulta por parte del cliente, y consulta
 * de todas las encuestas por parte del administrador.
 */
class SurveyController extends Controller
{
    private SurveyService $surveyService;

    public function __construct()
    {
        $this->surveyService = new SurveyService();
    }

    /**
     * Crear una nueva encuesta para una solicitud de servicio completada
     *
     * POST /api/surveys
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

            $validation = SurveyValidator::validateCreateRequest($request);
            if (!$validation['valid']) {
                return ResponseFormatter::validationError($validation['errors']);
            }

            $customerId = $request->getAttribute('userId');
            $serviceRequestId = (int)$request->input('service_request_id');

            $data = [
                'service_request_id'   => $serviceRequestId,
                'overall_satisfaction' => $request->input('overall_satisfaction'),
                'would_recommend'      => filter_var($request->input('would_recommend'), FILTER_VALIDATE_BOOLEAN),
                'comments'             => $request->input('comments') !== null
                    ? RequestValidator::sanitizeString($request->input('comments'))
                    : null,
            ];

            $this->surveyService->create($customerId, $data);

            return ResponseFormatter::success(null, 'Encuesta enviada correctamente', 201);

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Listar las encuestas enviadas por el cliente autenticado
     *
     * GET /api/surveys
     */
    public function index(Request $request): Response
    {
        try {
            $customerId = $request->getAttribute('userId');
            $surveys = $this->surveyService->getByCustomer($customerId);

            return ResponseFormatter::success([
                'surveys' => $surveys,
                'count'   => count($surveys),
            ], 'Encuestas obtenidas correctamente', 200);

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Listar todas las encuestas (administrador)
     *
     * GET /api/admin/surveys
     */
    public function adminIndex(Request $request): Response
    {
        try {
            $pagination = RequestValidator::parsePagination($request);
            $result = $this->surveyService->adminList($pagination['perPage'], $pagination['offset']);

            return ResponseFormatter::success([
                'surveys'    => $result['items'],
                'count'      => count($result['items']),
                'total'      => $result['total'],
                'page'       => $pagination['page'],
                'perPage'    => $pagination['perPage'],
                'totalPages' => (int)ceil($result['total'] / $pagination['perPage']),
            ], 'Encuestas obtenidas correctamente', 200);

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }
}
