<?php

namespace App\Models\Vehicle;

use App\Core\Request;
use App\Core\Http\RequestValidator;

/**
 * Validador de Vehículos
 *
 * Valida los datos de vehículos para creación y actualización.
 * Aplica reglas de negocio y restricciones de integridad de datos.
 */
class VehicleValidator
{
    /**
     * Tipos de vehículo válidos aceptados por el sistema
     */
    private const VALID_VEHICLE_TYPES = [
        'sedan',
        'suv',
        'truck',
        'motorcycle',
        'van',
        'other'
    ];

    /**
     * Tipos de combustible válidos aceptados por el sistema
     */
    private const VALID_FUEL_TYPES = [
        'gasoline',
        'diesel',
        'electric',
        'hybrid',
        'other'
    ];

    /**
     * Valores de estado válidos para un vehículo
     */
    private const VALID_STATUSES = [
        'active',
        'inactive'
    ];

    /**
     * Valida los datos para la creación de un vehículo.
     *
     * @param Request $request Solicitud HTTP
     * @return array           Resultado de validación ['valid' => bool, 'errors' => array]
     */
    public static function validateCreateRequest(Request $request): array
    {
        $errors = [];

        // license_plate (requerida)
        $licensePlate = $request->input('license_plate');
        if (empty($licensePlate)) {
            $errors['license_plate'] = 'La placa de matrícula es requerida';
        } elseif (strlen($licensePlate) > 20) {
            $errors['license_plate'] = 'La placa de matrícula no debe superar los 20 caracteres';
        }

        // make (requerido)
        $make = $request->input('make');
        if (empty($make)) {
            $errors['make'] = 'La marca del vehículo es requerida';
        } elseif (strlen($make) > 50) {
            $errors['make'] = 'La marca no debe superar los 50 caracteres';
        }

        // model (requerido)
        $model = $request->input('model');
        if (empty($model)) {
            $errors['model'] = 'El modelo del vehículo es requerido';
        } elseif (strlen($model) > 50) {
            $errors['model'] = 'El modelo no debe superar los 50 caracteres';
        }

        // year (requerido)
        $year = $request->input('year');
        if ($year === null || $year === '') {
            $errors['year'] = 'El año es requerido';
        } elseif (!is_numeric($year)) {
            $errors['year'] = 'El año debe ser un número';
        } else {
            $yearInt    = (int)$year;
            $currentYear = (int)date('Y');
            if ($yearInt < 1900 || $yearInt > ($currentYear + 1)) {
                $errors['year'] = "El año debe estar entre 1900 y " . ($currentYear + 1);
            }
        }

        // color (opcional)
        $color = $request->input('color');
        if ($color !== null && strlen($color) > 30) {
            $errors['color'] = 'El color no debe superar los 30 caracteres';
        }

        // vin (opcional, pero debe tener exactamente 17 caracteres si se proporciona)
        $vin = $request->input('vin');
        if ($vin !== null && $vin !== '') {
            if (strlen($vin) !== 17) {
                $errors['vin'] = 'El VIN debe tener exactamente 17 caracteres';
            } elseif (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', strtoupper($vin))) {
                $errors['vin'] = 'El VIN contiene caracteres inválidos';
            }
        }

        // vehicle_type (requerido)
        $vehicleType = $request->input('vehicle_type');
        if (empty($vehicleType)) {
            $errors['vehicle_type'] = 'El tipo de vehículo es requerido';
        } elseif (!in_array($vehicleType, self::VALID_VEHICLE_TYPES, true)) {
            $errors['vehicle_type'] = 'Tipo de vehículo inválido. Tipos válidos: ' . implode(', ', self::VALID_VEHICLE_TYPES);
        }

        // fuel_type (requerido)
        $fuelType = $request->input('fuel_type');
        if (empty($fuelType)) {
            $errors['fuel_type'] = 'El tipo de combustible es requerido';
        } elseif (!in_array($fuelType, self::VALID_FUEL_TYPES, true)) {
            $errors['fuel_type'] = 'Tipo de combustible inválido. Tipos válidos: ' . implode(', ', self::VALID_FUEL_TYPES);
        }

        // nickname (opcional)
        $nickname = $request->input('nickname');
        if ($nickname !== null && strlen($nickname) > 50) {
            $errors['nickname'] = 'El apodo no debe superar los 50 caracteres';
        }

        // primary_photo_url (opcional)
        $photoUrl = $request->input('primary_photo_url');
        if ($photoUrl !== null && strlen($photoUrl) > 255) {
            $errors['primary_photo_url'] = 'La URL de la foto no debe superar los 255 caracteres';
        } elseif ($photoUrl !== null && !filter_var($photoUrl, FILTER_VALIDATE_URL)) {
            $errors['primary_photo_url'] = 'La URL de la foto debe ser una URL válida';
        }

        // soat_number (opcional)
        $soatNumber = $request->input('soat_number');
        if ($soatNumber !== null && strlen($soatNumber) > 50) {
            $errors['soat_number'] = 'El número de SOAT no debe superar los 50 caracteres';
        }

        // soat_expiration_date (opcional)
        $soatExpirationDate = $request->input('soat_expiration_date');
        if ($soatExpirationDate !== null) {
            $parsed = \DateTime::createFromFormat('Y-m-d', $soatExpirationDate);
            if (!$parsed || $parsed->format('Y-m-d') !== $soatExpirationDate) {
                $errors['soat_expiration_date'] = 'La fecha de vencimiento del SOAT debe tener el formato YYYY-MM-DD';
            }
        }

        // soat_document_url (opcional)
        $soatDocumentUrl = $request->input('soat_document_url');
        if ($soatDocumentUrl !== null && (strlen($soatDocumentUrl) > 500 || !filter_var($soatDocumentUrl, FILTER_VALIDATE_URL))) {
            $errors['soat_document_url'] = 'La URL del documento SOAT debe ser válida y no superar los 500 caracteres';
        }

        // tecnomecanica_number (opcional)
        $tecnomecanicaNumber = $request->input('tecnomecanica_number');
        if ($tecnomecanicaNumber !== null && strlen($tecnomecanicaNumber) > 50) {
            $errors['tecnomecanica_number'] = 'El número de tecnomecánica no debe superar los 50 caracteres';
        }

        // tecnomecanica_expiration_date (opcional)
        $tecnomecanicaExpirationDate = $request->input('tecnomecanica_expiration_date');
        if ($tecnomecanicaExpirationDate !== null) {
            $parsed = \DateTime::createFromFormat('Y-m-d', $tecnomecanicaExpirationDate);
            if (!$parsed || $parsed->format('Y-m-d') !== $tecnomecanicaExpirationDate) {
                $errors['tecnomecanica_expiration_date'] = 'La fecha de vencimiento de la tecnomecánica debe tener el formato YYYY-MM-DD';
            }
        }

        // tecnomecanica_document_url (opcional)
        $tecnomecanicaDocumentUrl = $request->input('tecnomecanica_document_url');
        if ($tecnomecanicaDocumentUrl !== null && (strlen($tecnomecanicaDocumentUrl) > 500 || !filter_var($tecnomecanicaDocumentUrl, FILTER_VALIDATE_URL))) {
            $errors['tecnomecanica_document_url'] = 'La URL del documento de tecnomecánica debe ser válida y no superar los 500 caracteres';
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Valida los datos para la actualización de un vehículo.
     *
     * @param Request $request Solicitud HTTP
     * @return array           Resultado de validación ['valid' => bool, 'errors' => array]
     */
    public static function validateUpdateRequest(Request $request): array
    {
        $errors = [];

        // En las actualizaciones todos los campos son opcionales, pero deben ser válidos si se envían

        // license_plate
        $licensePlate = $request->input('license_plate');
        if ($licensePlate !== null) {
            if (empty($licensePlate)) {
                $errors['license_plate'] = 'La placa de matrícula no puede estar vacía';
            } elseif (strlen($licensePlate) > 20) {
                $errors['license_plate'] = 'La placa de matrícula no debe superar los 20 caracteres';
            }
        }

        // make
        $make = $request->input('make');
        if ($make !== null) {
            if (empty($make)) {
                $errors['make'] = 'La marca del vehículo no puede estar vacía';
            } elseif (strlen($make) > 50) {
                $errors['make'] = 'La marca no debe superar los 50 caracteres';
            }
        }

        // model
        $model = $request->input('model');
        if ($model !== null) {
            if (empty($model)) {
                $errors['model'] = 'El modelo del vehículo no puede estar vacío';
            } elseif (strlen($model) > 50) {
                $errors['model'] = 'El modelo no debe superar los 50 caracteres';
            }
        }

        // year
        $year = $request->input('year');
        if ($year !== null) {
            if (!is_numeric($year)) {
                $errors['year'] = 'El año debe ser un número';
            } else {
                $yearInt     = (int)$year;
                $currentYear = (int)date('Y');
                if ($yearInt < 1900 || $yearInt > ($currentYear + 1)) {
                    $errors['year'] = "El año debe estar entre 1900 y " . ($currentYear + 1);
                }
            }
        }

        // color
        $color = $request->input('color');
        if ($color !== null && strlen($color) > 30) {
            $errors['color'] = 'El color no debe superar los 30 caracteres';
        }

        // vin
        $vin = $request->input('vin');
        if ($vin !== null && $vin !== '') {
            if (strlen($vin) !== 17) {
                $errors['vin'] = 'El VIN debe tener exactamente 17 caracteres';
            } elseif (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', strtoupper($vin))) {
                $errors['vin'] = 'El VIN contiene caracteres inválidos';
            }
        }

        // vehicle_type
        $vehicleType = $request->input('vehicle_type');
        if ($vehicleType !== null && !in_array($vehicleType, self::VALID_VEHICLE_TYPES, true)) {
            $errors['vehicle_type'] = 'Tipo de vehículo inválido. Tipos válidos: ' . implode(', ', self::VALID_VEHICLE_TYPES);
        }

        // fuel_type
        $fuelType = $request->input('fuel_type');
        if ($fuelType !== null && !in_array($fuelType, self::VALID_FUEL_TYPES, true)) {
            $errors['fuel_type'] = 'Tipo de combustible inválido. Tipos válidos: ' . implode(', ', self::VALID_FUEL_TYPES);
        }

        // nickname
        $nickname = $request->input('nickname');
        if ($nickname !== null && strlen($nickname) > 50) {
            $errors['nickname'] = 'El apodo no debe superar los 50 caracteres';
        }

        // primary_photo_url
        $photoUrl = $request->input('primary_photo_url');
        if ($photoUrl !== null && strlen($photoUrl) > 255) {
            $errors['primary_photo_url'] = 'La URL de la foto no debe superar los 255 caracteres';
        } elseif ($photoUrl !== null && $photoUrl !== '' && !filter_var($photoUrl, FILTER_VALIDATE_URL)) {
            $errors['primary_photo_url'] = 'La URL de la foto debe ser una URL válida';
        }

        // status
        $status = $request->input('status');
        if ($status !== null && !in_array($status, self::VALID_STATUSES, true)) {
            $errors['status'] = 'Estado inválido. Estados válidos: ' . implode(', ', self::VALID_STATUSES);
        }

        // soat_number (opcional)
        $soatNumber = $request->input('soat_number');
        if ($soatNumber !== null && strlen($soatNumber) > 50) {
            $errors['soat_number'] = 'El número de SOAT no debe superar los 50 caracteres';
        }

        // soat_expiration_date (opcional)
        $soatExpirationDate = $request->input('soat_expiration_date');
        if ($soatExpirationDate !== null) {
            $parsed = \DateTime::createFromFormat('Y-m-d', $soatExpirationDate);
            if (!$parsed || $parsed->format('Y-m-d') !== $soatExpirationDate) {
                $errors['soat_expiration_date'] = 'La fecha de vencimiento del SOAT debe tener el formato YYYY-MM-DD';
            }
        }

        // soat_document_url (opcional)
        $soatDocumentUrl = $request->input('soat_document_url');
        if ($soatDocumentUrl !== null && (strlen($soatDocumentUrl) > 500 || !filter_var($soatDocumentUrl, FILTER_VALIDATE_URL))) {
            $errors['soat_document_url'] = 'La URL del documento SOAT debe ser válida y no superar los 500 caracteres';
        }

        // tecnomecanica_number (opcional)
        $tecnomecanicaNumber = $request->input('tecnomecanica_number');
        if ($tecnomecanicaNumber !== null && strlen($tecnomecanicaNumber) > 50) {
            $errors['tecnomecanica_number'] = 'El número de tecnomecánica no debe superar los 50 caracteres';
        }

        // tecnomecanica_expiration_date (opcional)
        $tecnomecanicaExpirationDate = $request->input('tecnomecanica_expiration_date');
        if ($tecnomecanicaExpirationDate !== null) {
            $parsed = \DateTime::createFromFormat('Y-m-d', $tecnomecanicaExpirationDate);
            if (!$parsed || $parsed->format('Y-m-d') !== $tecnomecanicaExpirationDate) {
                $errors['tecnomecanica_expiration_date'] = 'La fecha de vencimiento de la tecnomecánica debe tener el formato YYYY-MM-DD';
            }
        }

        // tecnomecanica_document_url (opcional)
        $tecnomecanicaDocumentUrl = $request->input('tecnomecanica_document_url');
        if ($tecnomecanicaDocumentUrl !== null && (strlen($tecnomecanicaDocumentUrl) > 500 || !filter_var($tecnomecanicaDocumentUrl, FILTER_VALIDATE_URL))) {
            $errors['tecnomecanica_document_url'] = 'La URL del documento de tecnomecánica debe ser válida y no superar los 500 caracteres';
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Normaliza una placa de matrícula.
     *
     * Convierte a mayúsculas, elimina espacios extremos y normaliza espacios internos.
     *
     * @param string $licensePlate Placa sin normalizar
     * @return string              Placa normalizada
     */
    public static function normalizeLicensePlate(string $licensePlate): string
    {
        // Convertir a mayúsculas
        $normalized = strtoupper($licensePlate);

        // Eliminar espacios al inicio y al final
        $normalized = trim($normalized);

        // Reemplazar múltiples espacios consecutivos por uno solo
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return $normalized;
    }

    /**
     * Normaliza un número VIN.
     *
     * Convierte a mayúsculas y elimina espacios extremos.
     *
     * @param string|null $vin VIN sin normalizar
     * @return string|null     VIN normalizado, o null si estaba vacío
     */
    public static function normalizeVIN(?string $vin): ?string
    {
        if ($vin === null || $vin === '') {
            return null;
        }

        // Convertir a mayúsculas y eliminar espacios
        return strtoupper(trim($vin));
    }

    /**
     * Retorna los tipos de vehículo válidos.
     *
     * @return array Lista de tipos de vehículo válidos
     */
    public static function getValidVehicleTypes(): array
    {
        return self::VALID_VEHICLE_TYPES;
    }

    /**
     * Retorna los tipos de combustible válidos.
     *
     * @return array Lista de tipos de combustible válidos
     */
    public static function getValidFuelTypes(): array
    {
        return self::VALID_FUEL_TYPES;
    }

    /**
     * Retorna los estados válidos de un vehículo.
     *
     * @return array Lista de estados válidos
     */
    public static function getValidStatuses(): array
    {
        return self::VALID_STATUSES;
    }
}
