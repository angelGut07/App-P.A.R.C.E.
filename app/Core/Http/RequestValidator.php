<?php

namespace App\Core\Http;

use App\Core\Request;

/**
 * Validador de Solicitudes
 *
 * Valida las solicitudes HTTP para los endpoints de la API, incluyendo el formato del correo electrónico,
 * la solidez de la contraseña, la presencia de campos, el Content-Type, el análisis de JSON y los límites de tamaño del cuerpo.
 *
 * Requisitos: 7.1-7.7, 15.1-15.7, 16.1-16.7
 */
class RequestValidator
{
    /**
     * Tamaño máximo del cuerpo de la solicitud en bytes (1 MB)
     */
    private const MAX_BODY_SIZE = 1048576; // 1 MB

    /**
     * Longitud mínima de contraseña
     */
    private const MIN_PASSWORD_LENGTH = 8;

    /**
     * Longitud máxima de contraseña
     */
    private const MAX_PASSWORD_LENGTH = 128;

    /**
     * Longitud máxima del correo electrónico
     */
    private const MAX_EMAIL_LENGTH = 255;

    /**
     * Valida el formato del correo electrónico
     *
     * Requisito 7.1: Verificar que el correo electrónico tenga un formato válido
     *
     * @param string $email Dirección de correo electrónico a validar
     * @return bool Verdadero si es válido, falso en caso contrario
     */
    public static function isValidEmail(string $email): bool
    {
        // Verificar la longitud
        if (strlen($email) > self::MAX_EMAIL_LENGTH) {
            return false;
        }

        // Validar el formato usando filter_var
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Verificación adicional con patrón para una validación más estricta
        $pattern = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';
        return preg_match($pattern, $email) === 1;
    }

    /**
     * Valida la solidez de la contraseña
     *
     * Requisito 7.2: Verificar que la contraseña sea una cadena no vacía de al menos 8 caracteres
     *
     * @param string $password Contraseña a validar
     * @return bool Verdadero si es válida, falso en caso contrario
     */
    public static function isValidPassword(string $password): bool
    {
        $length = strlen($password);

        // Verificar la longitud mínima
        if ($length < self::MIN_PASSWORD_LENGTH) {
            return false;
        }

        // Verificar la longitud máxima
        if ($length > self::MAX_PASSWORD_LENGTH) {
            return false;
        }

        // Verificar bytes nulos (seguridad)
        if (str_contains($password, "\0")) {
            return false;
        }

        return true;
    }

    /**
     * Valida que los campos requeridos estén presentes
     *
     * Requisito 7.5: Verificar que los campos requeridos estén presentes
     *
     * @param Request $request Objeto de solicitud HTTP
     * @param array $requiredFields Arreglo de nombres de campos requeridos
     * @return array Arreglo de nombres de campos faltantes (vacío si todos están presentes)
     */
    public static function validateRequiredFields(Request $request, array $requiredFields): array
    {
        $missing = [];

        foreach ($requiredFields as $field) {
            if (!$request->has($field) || $request->input($field) === '' || $request->input($field) === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Valida el encabezado Content-Type
     *
     * Requisitos: 15.1, 15.2, 15.3, 15.4, 15.5
     *
     * @param Request $request Objeto de solicitud HTTP
     * @param string $method Método HTTP (GET, POST, etc.)
     * @return array Arreglo de error con booleano 'valid' y mensaje 'error' opcional
     */
    public static function validateContentType(Request $request, string $method): array
    {
        // Las solicitudes GET y OPTIONS no requieren validación de Content-Type
        if (in_array(strtoupper($method), ['GET', 'OPTIONS'])) {
            return ['valid' => true];
        }

        $contentType = $request->header('Content-Type', '');

        // Content-Type ausente
        if (empty($contentType)) {
            return [
                'valid' => false,
                'error' => 'Falta el encabezado Content-Type',
                'statusCode' => 415
            ];
        }

        // Normalizar el tipo de contenido (eliminar charset y espacios en blanco)
        $baseType = strtolower(trim(explode(';', $contentType)[0]));

        // Verificar si el tipo base es application/json
        if ($baseType !== 'application/json') {
            return [
                'valid' => false,
                'error' => 'El Content-Type debe ser application/json',
                'statusCode' => 415
            ];
        }

        return ['valid' => true];
    }

    /**
     * Analiza y valida el cuerpo de la solicitud JSON
     *
     * Requisitos: 16.1, 16.2, 16.3, 16.4, 16.5, 16.6, 16.7
     *
     * @param Request $request Objeto de solicitud HTTP
     * @return array Arreglo de resultado con booleano 'valid', 'data' o 'error' opcionales
     */
    public static function parseJsonBody(Request $request): array
    {
        // Verificar el tamaño del cuerpo de la solicitud
        $bodySize = (int)$request->header('Content-Length', 0);
        if ($bodySize > self::MAX_BODY_SIZE) {
            return [
                'valid' => false,
                'error' => 'El cuerpo de la petición supera 1 MB',
                'statusCode' => 413
            ];
        }

        // Obtener el cuerpo sin procesar (ya leído una única vez por Request::__construct())
        $rawBody = $request->rawBody();

        // Verificar si el cuerpo está vacío para solicitudes POST
        if (empty($rawBody) && $request->method() === 'POST') {
            return [
                'valid' => false,
                'error' => 'El cuerpo de la petición no puede estar vacío',
                'statusCode' => 400
            ];
        }

        // Analizar JSON
        $data = json_decode($rawBody, true);

        // Verificar errores de análisis JSON
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'valid' => false,
                'error' => 'El cuerpo de la petición no es un JSON válido',
                'statusCode' => 400
            ];
        }

        // Verificar que el resultado sea un objeto JSON (no un arreglo ni un primitivo)
        if (!is_array($data) || array_keys($data) === range(0, count($data) - 1)) {
            return [
                'valid' => false,
                'error' => 'El cuerpo de la petición debe ser un objeto JSON',
                'statusCode' => 400
            ];
        }

        return [
            'valid' => true,
            'data' => $data
        ];
    }

    /**
     * Sanitiza la entrada de cadena para prevenir ataques de inyección
     *
     * Requisito 7.6: Sanitizar todas las entradas de cadena
     *
     * @param string $input Cadena de entrada a sanitizar
     * @return string Cadena sanitizada
     */
    public static function sanitizeString(string $input): string
    {
        // Eliminar bytes nulos
        $input = str_replace("\0", '', $input);

        // Eliminar espacios en blanco al inicio y al final
        $input = trim($input);

        return $input;
    }

    /**
     * Valida todos los campos de una solicitud de inicio de sesión
     *
     * Requisitos: 7.1, 7.2, 7.3, 7.5
     *
     * @param Request $request Objeto de solicitud HTTP
     * @return array Resultado de validación con booleano 'valid' y arreglo 'errors' opcional
     */
    public static function validateLoginRequest(Request $request): array
    {
        $errors = [];

        // Verificar los campos requeridos
        $missing = self::validateRequiredFields($request, ['email', 'password']);
        if (!empty($missing)) {
            return [
                'valid' => false,
                'errors' => [
                    'error' => 'Faltan campos requeridos',
                    'fields' => $missing
                ]
            ];
        }

        // Validar el correo electrónico
        $email = $request->input('email');
        if (!self::isValidEmail($email)) {
            $errors['email'] = ['El correo electrónico no es válido'];
        }

        // Validar la contraseña
        $password = $request->input('password');
        if (!self::isValidPassword($password)) {
            $errors['password'] = ['La contraseña debe tener entre 8 y 128 caracteres'];
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'errors' => $errors
            ];
        }

        return ['valid' => true];
    }

    /**
     * Valida los campos de una solicitud de registro
     *
     * @param Request $request Objeto de solicitud HTTP
     * @return array Resultado de validación con booleano 'valid' y arreglo 'errors' opcional
     */
    public static function validateRegistrationRequest(Request $request): array
    {
        $errors = [];

        // Verificar los campos requeridos
        $missing = self::validateRequiredFields($request, [
            'email',
            'password',
            'password_confirmation',
            'first_name',
            'last_name'
        ]);

        if (!empty($missing)) {
            return [
                'valid' => false,
                'errors' => [
                    'error' => 'Faltan campos requeridos',
                    'fields' => $missing
                ]
            ];
        }

        // Validar el correo electrónico
        $email = $request->input('email');
        if (!self::isValidEmail($email)) {
            $errors['email'] = ['El correo electrónico no es válido'];
        }

        // Validar la contraseña
        $password = $request->input('password');
        if (!self::isValidPassword($password)) {
            $errors['password'] = ['La contraseña debe tener entre 8 y 128 caracteres'];
        }

        // Validar la confirmación de contraseña
        $passwordConfirmation = $request->input('password_confirmation');
        if ($password !== $passwordConfirmation) {
            $errors['password_confirmation'] = ['Las contraseñas no coinciden'];
        }

        // Longitud de nombre/apellido (users.first_name/last_name VARCHAR(100)) — sin
        // este límite, un valor más largo pasa la validación y falla más abajo con un
        // error de BD genérico en vez de un 400 claro.
        if (strlen($request->input('first_name')) > 100) {
            $errors['first_name'] = ['El nombre no debe superar los 100 caracteres'];
        }

        if (strlen($request->input('last_name')) > 100) {
            $errors['last_name'] = ['El apellido no debe superar los 100 caracteres'];
        }

        // Teléfono (opcional, users.phone VARCHAR(20))
        $phone = $request->input('phone');
        if ($phone !== null && strlen($phone) > 20) {
            $errors['phone'] = ['El teléfono no debe superar los 20 caracteres'];
        }

        // Cédula (opcional): solo dígitos, entre 6 y 10
        $idNumber = $request->input('id_number');
        if ($idNumber !== null && $idNumber !== '' && !preg_match('/^\d{6,10}$/', (string)$idNumber)) {
            $errors['id_number'] = ['La cédula debe tener entre 6 y 10 dígitos'];
        }

        // Licencia de conducción (opcional en el registro, users.driver_license_number VARCHAR(50))
        $license = $request->input('driver_license_number');
        if ($license !== null && strlen((string)$license) > 50) {
            $errors['driver_license_number'] = ['El número de licencia no debe superar los 50 caracteres'];
        }

        // Rol solicitado: 'customer' (por defecto) o 'mechanic'. Pedir 'mechanic'
        // NO concede el rol: la cuenta se crea como cliente y se abre una
        // solicitud que un administrador debe aprobar.
        $role = $request->input('role');
        if ($role !== null && !in_array($role, ['customer', 'mechanic'], true)) {
            $errors['role'] = ['Rol inválido. Valores válidos: customer, mechanic'];
        }

        if ($role === 'mechanic') {
            if (empty($license)) {
                $errors['driver_license_number'] = ['La licencia de conducción es requerida para mecánicos'];
            }
            foreach (\App\Models\MechanicApplication\MechanicApplicationValidator::validateData(
                \App\Models\MechanicApplication\MechanicApplicationValidator::extractData($request)
            ) as $field => $message) {
                $errors[$field] = [$message];
            }
        }

        // Vehículo (opcional): si se envía la placa, se exigen los demás datos básicos
        $plate = $request->input('vehicle_plate');
        if ($plate !== null && $plate !== '') {
            if (!preg_match('/^[A-Za-z0-9-]{5,10}$/', (string)$plate)) {
                $errors['vehicle_plate'] = ['La placa no es válida'];
            }
            if (empty($request->input('vehicle_brand'))) {
                $errors['vehicle_brand'] = ['La marca del vehículo es requerida'];
            }
            if (empty($request->input('vehicle_model'))) {
                $errors['vehicle_model'] = ['El modelo del vehículo es requerido'];
            }
            $year = filter_var($request->input('vehicle_year'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1950, 'max_range' => (int)date('Y') + 1]]);
            if ($year === false) {
                $errors['vehicle_year'] = ['El año del vehículo no es válido'];
            }
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'errors' => $errors
            ];
        }

        return ['valid' => true];
    }

    /**
     * Valida una solicitud de cambio de contraseña
     *
     * @param Request $request Solicitud HTTP
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validateChangePasswordRequest(Request $request): array
    {
        $errors = [];

        $missing = self::validateRequiredFields($request, [
            'current_password',
            'new_password',
            'new_password_confirmation'
        ]);

        if (!empty($missing)) {
            return [
                'valid' => false,
                'errors' => [
                    'error' => 'Faltan campos requeridos',
                    'fields' => $missing
                ]
            ];
        }

        $newPassword = $request->input('new_password');
        if (!self::isValidPassword($newPassword)) {
            $errors['new_password'] = ['La contraseña debe tener entre 8 y 128 caracteres'];
        }

        $newPasswordConfirmation = $request->input('new_password_confirmation');
        if ($newPassword !== $newPasswordConfirmation) {
            $errors['new_password_confirmation'] = ['Las contraseñas no coinciden'];
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'errors' => $errors
            ];
        }

        return ['valid' => true];
    }

    /**
     * Valida la solicitud de "olvidé mi contraseña" (solo requiere un email
     * con formato válido — no se revela si la cuenta existe o no).
     *
     * @param Request $request
     * @return array
     */
    public static function validateForgotPasswordRequest(Request $request): array
    {
        $email = $request->input('email');

        if (empty($email) || !self::isValidEmail($email)) {
            return [
                'valid' => false,
                'errors' => ['email' => ['A valid email is required']]
            ];
        }

        return ['valid' => true];
    }

    /**
     * Valida la solicitud de restablecimiento de contraseña con token.
     *
     * @param Request $request
     * @return array
     */
    public static function validateResetPasswordRequest(Request $request): array
    {
        $errors = [];

        $missing = self::validateRequiredFields($request, [
            'token',
            'new_password',
            'new_password_confirmation'
        ]);

        if (!empty($missing)) {
            return [
                'valid' => false,
                'errors' => [
                    'error' => 'Faltan campos requeridos',
                    'fields' => $missing
                ]
            ];
        }

        $newPassword = $request->input('new_password');
        if (!self::isValidPassword($newPassword)) {
            $errors['new_password'] = ['La contraseña debe tener entre 8 y 128 caracteres'];
        }

        if ($newPassword !== $request->input('new_password_confirmation')) {
            $errors['new_password_confirmation'] = ['Las contraseñas no coinciden'];
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'errors' => $errors
            ];
        }

        return ['valid' => true];
    }

    /**
     * Extrae y normaliza los parámetros de paginación (page/per_page) de una
     * solicitud. Valores inválidos o ausentes usan los valores por defecto en
     * vez de fallar — la paginación es un refinamiento opcional del listado,
     * no un dato requerido.
     *
     * @param Request $request
     * @param int     $defaultPerPage Tamaño de página por defecto si no se especifica
     * @param int     $maxPerPage     Límite superior para evitar páginas arbitrariamente grandes
     * @return array ['page' => int, 'perPage' => int, 'offset' => int]
     */
    public static function parsePagination(Request $request, int $defaultPerPage = 50, int $maxPerPage = 200): array
    {
        $page = (int)($request->query('page') ?? 1);
        if ($page < 1) {
            $page = 1;
        }

        $perPage = (int)($request->query('per_page') ?? $defaultPerPage);
        if ($perPage < 1) {
            $perPage = $defaultPerPage;
        } elseif ($perPage > $maxPerPage) {
            $perPage = $maxPerPage;
        }

        return [
            'page'    => $page,
            'perPage' => $perPage,
            'offset'  => ($page - 1) * $perPage,
        ];
    }
}
