<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Vehicle\VehicleService;
use App\Models\Vehicle\VehicleValidator;
use App\Core\Http\RequestValidator;
use App\Core\Http\ResponseFormatter;
use App\Core\Http\ErrorHandler;

/**
 * Controlador de Vehículos
 *
 * Maneja las peticiones HTTP para gestión de vehículos:
 * creación, consulta, actualización, eliminación y designación de vehículo principal.
 */
class VehicleController extends Controller
{
    private VehicleService $vehicleService;

    public function __construct()
    {
        $this->vehicleService = new VehicleService();
    }

    /**
     * Convierte una fila de la base de datos (snake_case) al formato camelCase
     * esperado por el frontend.
     *
     * ResponseFormatter::success() ya convierte claves a camelCase
     * automáticamente y de forma recursiva — este método existe además para
     * hacer el *type casting* explícito que ese conversor genérico no hace
     * (ej. `is_primary` es un TINYINT de MySQL; sin el `(bool)` de abajo
     * llegaría al frontend como `0`/`1` en vez de `false`/`true`, rompiendo
     * el tipo `isPrimary: boolean` silenciosamente). No eliminar asumiendo
     * que es redundante con el conversor genérico.
     *
     * @param array $vehicle Fila de la tabla vehicles
     * @return array Vehículo formateado
     */
    private function formatVehicle(array $vehicle): array
    {
        return [
            'id'                          => (int)$vehicle['id'],
            'userId'                      => (int)$vehicle['user_id'],
            'licensePlate'                => $vehicle['license_plate'],
            'make'                        => $vehicle['make'],
            'model'                       => $vehicle['model'],
            'year'                        => (int)$vehicle['year'],
            'color'                       => $vehicle['color'],
            'vin'                         => $vehicle['vin'],
            'vehicleType'                 => $vehicle['vehicle_type'],
            'fuelType'                    => $vehicle['fuel_type'],
            'nickname'                    => $vehicle['nickname'],
            'primaryPhotoUrl'             => $vehicle['primary_photo_url'],
            'isPrimary'                   => (bool)$vehicle['is_primary'],
            'status'                      => $vehicle['status'],
            'soatNumber'                  => $vehicle['soat_number'] ?? null,
            'soatExpirationDate'          => $vehicle['soat_expiration_date'] ?? null,
            'soatDocumentUrl'             => $vehicle['soat_document_url'] ?? null,
            'tecnomecanicaNumber'         => $vehicle['tecnomecanica_number'] ?? null,
            'tecnomecanicaExpirationDate' => $vehicle['tecnomecanica_expiration_date'] ?? null,
            'tecnomecanicaDocumentUrl'    => $vehicle['tecnomecanica_document_url'] ?? null,
            'createdAt'                   => $vehicle['created_at'],
            'updatedAt'                   => $vehicle['updated_at'],
        ];
    }

    /**
     * Campos de documentos (SOAT y tecnomecánica) presentes en la petición,
     * ya validados por VehicleValidator. VehicleService los guarda en
     * `vehicle_documents`.
     */
    private function documentInput(Request $request): array
    {
        $data = [];
        foreach (['soat', 'tecnomecanica'] as $type) {
            foreach (['number', 'expiration_date', 'document_url'] as $field) {
                $key = "{$type}_{$field}";
                if ($request->input($key) !== null) {
                    $data[$key] = RequestValidator::sanitizeString((string)$request->input($key));
                }
            }
        }
        return $data;
    }

    /**
     * Listar vehículos del usuario
     *
     * GET /api/vehicles
     */
    public function index(Request $request): Response
    {
        try {
            $userId     = $request->getAttribute('userId');
            $activeOnly = $request->query('active_only', 'true') !== 'false';

            $vehicles         = $this->vehicleService->getUserVehicles($userId, $activeOnly);
            $formattedVehicles = array_map([$this, 'formatVehicle'], $vehicles);

            return ResponseFormatter::success([
                'vehicles' => $formattedVehicles,
                'count'    => count($formattedVehicles),
            ], 'Vehículos obtenidos correctamente', 200);

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Crear nuevo vehículo
     *
     * POST /api/vehicles
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

            $validation = VehicleValidator::validateCreateRequest($request);
            if (!$validation['valid']) {
                return ResponseFormatter::validationError($validation['errors']);
            }

            $userId = $request->getAttribute('userId');

            $data = [
                'license_plate' => RequestValidator::sanitizeString($request->input('license_plate')),
                'make'          => RequestValidator::sanitizeString($request->input('make')),
                'model'         => RequestValidator::sanitizeString($request->input('model')),
                'year'          => $request->input('year'),
                'vehicle_type'  => $request->input('vehicle_type'),
                'fuel_type'     => $request->input('fuel_type'),
            ];

            if ($request->input('color') !== null)
                $data['color'] = RequestValidator::sanitizeString($request->input('color'));

            if ($request->input('vin') !== null)
                $data['vin'] = RequestValidator::sanitizeString($request->input('vin'));

            if ($request->input('nickname') !== null)
                $data['nickname'] = RequestValidator::sanitizeString($request->input('nickname'));

            if ($request->input('primary_photo_url') !== null)
                $data['primary_photo_url'] = RequestValidator::sanitizeString($request->input('primary_photo_url'));

            if ($request->input('is_primary') !== null)
                $data['is_primary'] = filter_var($request->input('is_primary'), FILTER_VALIDATE_BOOLEAN);

            $data += $this->documentInput($request);

            $vehicleId = $this->vehicleService->create($userId, $data);
            $vehicle   = $this->vehicleService->getById($vehicleId, $userId);

            return ResponseFormatter::success(
                ['vehicle' => $this->formatVehicle($vehicle)],
                'Vehículo creado correctamente',
                201
            );

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Obtener detalle de un vehículo
     *
     * GET /api/vehicles/{id}
     */
    public function show(Request $request, int $id): Response
    {
        try {
            $userId  = $request->getAttribute('userId');
            $vehicle = $this->vehicleService->getById($id, $userId);

            if ($vehicle === null) {
                return ResponseFormatter::notFound('Vehículo no encontrado');
            }

            return ResponseFormatter::success(
                ['vehicle' => $this->formatVehicle($vehicle)],
                'Vehículo obtenido correctamente',
                200
            );

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Actualizar vehículo
     *
     * PUT /api/vehicles/{id}
     */
    public function update(Request $request, int $id): Response
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

            $validation = VehicleValidator::validateUpdateRequest($request);
            if (!$validation['valid']) {
                return ResponseFormatter::validationError($validation['errors']);
            }

            $userId = $request->getAttribute('userId');
            $data   = [];

            if ($request->input('license_plate') !== null)
                $data['license_plate'] = RequestValidator::sanitizeString($request->input('license_plate'));
            if ($request->input('make') !== null)
                $data['make'] = RequestValidator::sanitizeString($request->input('make'));
            if ($request->input('model') !== null)
                $data['model'] = RequestValidator::sanitizeString($request->input('model'));
            if ($request->input('year') !== null)
                $data['year'] = $request->input('year');
            if ($request->input('color') !== null)
                $data['color'] = RequestValidator::sanitizeString($request->input('color'));
            if ($request->input('vin') !== null)
                $data['vin'] = RequestValidator::sanitizeString($request->input('vin'));
            if ($request->input('vehicle_type') !== null)
                $data['vehicle_type'] = $request->input('vehicle_type');
            if ($request->input('fuel_type') !== null)
                $data['fuel_type'] = $request->input('fuel_type');
            if ($request->input('nickname') !== null)
                $data['nickname'] = RequestValidator::sanitizeString($request->input('nickname'));
            if ($request->input('primary_photo_url') !== null)
                $data['primary_photo_url'] = RequestValidator::sanitizeString($request->input('primary_photo_url'));
            if ($request->input('status') !== null)
                $data['status'] = $request->input('status');
            if ($request->input('is_primary') !== null)
                $data['is_primary'] = filter_var($request->input('is_primary'), FILTER_VALIDATE_BOOLEAN);

            $data += $this->documentInput($request);

            $this->vehicleService->update($id, $userId, $data);
            $vehicle = $this->vehicleService->getById($id, $userId);

            return ResponseFormatter::success(
                ['vehicle' => $this->formatVehicle($vehicle)],
                'Vehículo actualizado correctamente',
                200
            );

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Eliminar vehículo (soft delete)
     *
     * DELETE /api/vehicles/{id}
     */
    public function destroy(Request $request, int $id): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $this->vehicleService->delete($id, $userId);

            return ResponseFormatter::success(null, 'Vehículo eliminado correctamente', 200);

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Establecer vehículo como principal
     *
     * PUT /api/vehicles/{id}/primary
     */
    public function setPrimary(Request $request, int $id): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $this->vehicleService->setPrimary($id, $userId);
            $vehicle = $this->vehicleService->getById($id, $userId);

            return ResponseFormatter::success(
                ['vehicle' => $this->formatVehicle($vehicle)],
                'Vehículo principal establecido correctamente',
                200
            );

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }
}
