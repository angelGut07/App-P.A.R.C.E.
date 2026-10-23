<?php

namespace App\Controllers\Auth;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\DomainException;
use App\Models\Auth\AuthService;
use App\Models\Auth\SessionManager;
use App\Models\Auth\PasswordHasher;
use App\Models\Auth\RoleValidator;
use App\Models\Auth\PasswordResetService;
use App\Models\Mail\MailerService;
use App\Core\Http\RequestValidator;
use App\Core\Http\ResponseFormatter;
use App\Core\Http\RateLimiter;
use App\Core\Http\IPValidator;
use App\Core\Http\ErrorHandler;
use App\Models\Auth\AuthenticationException;
use App\Models\MechanicApplication\MechanicApplicationService;
use App\Models\MechanicApplication\MechanicApplicationValidator;
use App\Models\Upload\UploadService;

/**
 * Authentication Controller
 * 
 * Handles HTTP requests for authentication operations including
 * registration, login, logout, and current user retrieval.
 * 
 * Requirements: Design API Endpoints 1-4
 */
class AuthController extends Controller
{
    private AuthService $authService;
    private SessionManager $sessionManager;
    private PasswordHasher $passwordHasher;
    private RoleValidator $roleValidator;
    private PasswordResetService $passwordResetService;

    public function __construct()
    {
        $this->passwordHasher = new PasswordHasher();
        $this->sessionManager = new SessionManager();
        $this->authService = new AuthService($this->passwordHasher, $this->sessionManager);
        $this->roleValidator = new RoleValidator();
        $this->passwordResetService = new PasswordResetService(
            $this->passwordHasher,
            $this->authService,
            $this->sessionManager,
            new MailerService()
        );
    }

    /**
     * Register new user
     * 
     * POST /api/auth/register
     * 
     * Requirements: 1.1-1.8, Design API Endpoint 1
     * 
     * @param Request $request HTTP request
     * @return Response HTTP response
     */
    public function register(Request $request): Response
    {
        try {
            // Requirement 20.1, 20.2: obtener IP del cliente para limitar tasa
            $ipAddress = IPValidator::getClientIP($request);

            // Limitar tasa de registros por IP para evitar creación masiva de cuentas
            $rateLimitCheck = RateLimiter::check('register', $ipAddress);

            if (!$rateLimitCheck['allowed']) {
                $retryAfter = $rateLimitCheck['reset_at'] - time();
                return ResponseFormatter::rateLimitExceeded($retryAfter);
            }

            // Validate Content-Type
            $contentTypeValidation = RequestValidator::validateContentType($request, 'POST');
            if (!$contentTypeValidation['valid']) {
                return ResponseFormatter::error(
                    $contentTypeValidation['error'],
                    null,
                    $contentTypeValidation['statusCode']
                );
            }

            // Parse JSON body
            $jsonValidation = RequestValidator::parseJsonBody($request);
            if (!$jsonValidation['valid']) {
                return ResponseFormatter::error(
                    $jsonValidation['error'],
                    null,
                    $jsonValidation['statusCode']
                );
            }

            // Validate registration request
            $validation = RequestValidator::validateRegistrationRequest($request);
            if (!$validation['valid']) {
                return ResponseFormatter::validationError($validation['errors']);
            }

            // Extract and sanitize input
            $email = RequestValidator::sanitizeString($request->input('email'));
            $password = $request->input('password');
            $optional = fn(string $key) => $request->input($key) !== null && $request->input($key) !== ''
                ? RequestValidator::sanitizeString((string)$request->input($key))
                : null;

            $profile = [
                'first_name'            => RequestValidator::sanitizeString($request->input('first_name')),
                'last_name'             => RequestValidator::sanitizeString($request->input('last_name')),
                'phone'                 => $optional('phone'),
                'id_number'             => $optional('id_number'),
                'driver_license_number' => $optional('driver_license_number') !== null
                    ? strtoupper($optional('driver_license_number'))
                    : null,
            ];

            // Vehículo opcional (el validador ya exige marca/modelo/año si viene la placa)
            $vehicle = null;
            if ($optional('vehicle_plate') !== null) {
                $vehicle = [
                    'license_plate'        => $optional('vehicle_plate'),
                    'make'                 => $optional('vehicle_brand'),
                    'model'                => $optional('vehicle_model'),
                    'year'                 => (int)$request->input('vehicle_year'),
                    'color'                => $optional('vehicle_color'),
                    'soat_number'          => $optional('soat_number'),
                    'tecnomecanica_number' => $optional('tecnomecanica_number'),
                ];
            }

            // Pedir el rol 'mechanic' no lo concede: se abre una solicitud que
            // un administrador debe aprobar (MechanicApplicationService::approve()).
            $mechanicApplication = $request->input('role') === 'mechanic'
                ? MechanicApplicationValidator::extractData($request)
                : null;

            // Check if email already exists
            if ($this->authService->emailExists($email)) {
                RateLimiter::recordAttempt('register', $ipAddress);
                return ResponseFormatter::conflict('Ya existe una cuenta con este correo electrónico');
            }

            // Hash password
            $passwordHash = $this->passwordHasher->hash($password);

            // Usuario, rol customer, vehículo, solicitud de mecánico y sesión en una única transacción
            $authResult = $this->authService->register(
                $email,
                $passwordHash,
                $profile,
                IPValidator::getClientIP($request),
                $request->userAgent(),
                $vehicle,
                $mechanicApplication
            );

            // Contar el registro exitoso para el límite de tasa (evita creación masiva)
            RateLimiter::recordAttempt('register', $ipAddress);

            // Prepare response data — mismo perfil completo que devuelve GET /api/auth/me
            $responseData = [
                'user' => $this->buildUserProfile($authResult->userId),
                'session' => [
                    'id' => $authResult->sessionId,
                    'expiresAt' => time() + 7200 // 2 hours
                ]
            ];

            // Set session cookie
            $response = ResponseFormatter::success(
                $responseData,
                'Cuenta creada correctamente',
                201
            );

            ResponseFormatter::setSessionCookie($response, $authResult->sessionId, false);

            return $response;

        } catch (AuthenticationException $e) {
            return ErrorHandler::handleException($e);

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Login user
     * 
     * POST /api/auth/login
     * 
     * Requirements: 1.1-1.8, 19.1-19.7, Design API Endpoint 2
     * 
     * @param Request $request HTTP request
     * @return Response HTTP response
     */
    public function login(Request $request): Response
    {
        try {
            // Requirement 20.1, 20.2: Get client IP address using IPValidator
            $ipAddress = IPValidator::getClientIP($request);

            // Requirement 6.1, 6.2, 6.3: Check rate limit before authentication
            $rateLimitCheck = RateLimiter::check('login', $ipAddress);
            
            if (!$rateLimitCheck['allowed']) {
                // Requirement 6.4: Return 429 with Retry-After header
                $retryAfter = $rateLimitCheck['reset_at'] - time();
                return ResponseFormatter::rateLimitExceeded($retryAfter);
            }

            // Validate Content-Type
            $contentTypeValidation = RequestValidator::validateContentType($request, 'POST');
            if (!$contentTypeValidation['valid']) {
                return ResponseFormatter::error(
                    $contentTypeValidation['error'],
                    null,
                    $contentTypeValidation['statusCode']
                );
            }

            // Parse JSON body
            $jsonValidation = RequestValidator::parseJsonBody($request);
            if (!$jsonValidation['valid']) {
                return ResponseFormatter::error(
                    $jsonValidation['error'],
                    null,
                    $jsonValidation['statusCode']
                );
            }

            // Validate login request
            $validation = RequestValidator::validateLoginRequest($request);
            if (!$validation['valid']) {
                return ResponseFormatter::validationError($validation['errors']);
            }

            // Extract input
            $email = RequestValidator::sanitizeString($request->input('email'));
            $password = $request->input('password');
            $remember = $request->input('remember', false);

            // Normalize remember to boolean
            if (!is_bool($remember)) {
                $remember = filter_var($remember, FILTER_VALIDATE_BOOLEAN);
            }

            // Requirement 20.2: Authenticate via AuthService with IP address and user agent
            $userAgent = $request->userAgent();
            $authResult = $this->authService->authenticate($email, $password, $remember, $ipAddress, $userAgent);

            // Check authentication result
            if (!$authResult->success) {
                // Record failed attempt for rate limiting
                RateLimiter::recordAttempt('login', $ipAddress);
                
                // Return 401 with generic error message (prevent user enumeration)
                return ResponseFormatter::unauthorized($authResult->message);
            }

            // Requirement 6.5: Reset rate limit on successful login
            RateLimiter::reset('login', $ipAddress);

            // Calculate session expiration
            $expiresAt = $remember
                ? time() + (30 * 24 * 60 * 60) // 30 days
                : time() + 7200; // 2 hours

            // Prepare response data — mismo perfil completo que devuelve GET /api/auth/me
            // (incluye phone, createdAt y driverLicense) para que el frontend no dependa
            // de un refresh posterior para ver esos campos.
            $responseData = [
                'user' => $this->buildUserProfile($authResult->userId),
                'session' => [
                    'id' => $authResult->sessionId,
                    'expiresAt' => $expiresAt
                ]
            ];

            // Set session cookie
            $response = ResponseFormatter::success(
                $responseData,
                'Sesión iniciada correctamente',
                200
            );

            ResponseFormatter::setSessionCookie($response, $authResult->sessionId, $remember);

            return $response;

        } catch (AuthenticationException $e) {
            // Record failed attempt for rate limiting
            RateLimiter::recordAttempt('login', $ipAddress);
            
            return ErrorHandler::handleException($e);

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Logout user
     * 
     * POST /api/auth/logout
     * 
     * Requirements: 2.1-2.7, Design API Endpoint 3
     * 
     * @param Request $request HTTP request
     * @return Response HTTP response
     */
    public function logout(Request $request): Response
    {
        try {
            // Extract session ID from cookie
            $sessionId = $request->cookie(ResponseFormatter::getSessionCookieName());

            // If no session cookie, return success anyway (idempotent)
            if ($sessionId === null || empty($sessionId)) {
                $response = ResponseFormatter::success(null, 'Sesión cerrada correctamente', 200);
                ResponseFormatter::clearSessionCookie($response);
                return $response;
            }

            // Destroy session via AuthService
            $this->authService->logout($sessionId);

            // Clear session cookie
            $response = ResponseFormatter::success(null, 'Sesión cerrada correctamente', 200);
            ResponseFormatter::clearSessionCookie($response);

            return $response;

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Obtener usuario autenticado actual
     *
     * GET /api/auth/me
     *
     * Retorna el perfil completo del usuario incluyendo datos de licencia de conducción.
     *
     * @param Request $request HTTP request
     * @return Response HTTP response
     */
    public function me(Request $request): Response
    {
        try {
            // Obtener usuario desde los atributos del request (establecido por AuthMiddleware)
            $user = $request->getAttribute('user');

            if ($user === null) {
                return ResponseFormatter::unauthorized('Autenticación requerida');
            }

            $responseData = $this->buildUserProfile((int)$user['id']);

            if ($responseData === null) {
                return ResponseFormatter::unauthorized('Usuario no encontrado');
            }

            return ResponseFormatter::success($responseData, 'Usuario obtenido correctamente', 200);

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Construye el perfil completo de un usuario (mismos campos que GET /api/auth/me).
     *
     * Usado por register(), login() y me() para que los tres endpoints devuelvan
     * exactamente la misma forma de objeto `user` — antes cada uno armaba su propio
     * arreglo a mano y quedaban desincronizados (login/register nunca incluían
     * `phone`, `createdAt` ni `driverLicense`, rompiendo cualquier UI que dependiera
     * de esos campos justo después de iniciar sesión, antes de un refresh manual).
     *
     * @param int $userId ID del usuario
     * @return array|null Perfil completo, o null si el usuario no existe
     */
    private function buildUserProfile(int $userId): ?array
    {
        $userData = $this->authService->getUserProfileData($userId);

        if ($userData === null) {
            return null;
        }

        $roles = $this->roleValidator->getUserRoles($userId);

        // Licencia de conducción (null si no se ha registrado). El estado se
        // calcula a partir de la fecha de vencimiento, no se guarda.
        $driverLicense = null;
        if ($userData['driver_license_number'] !== null || $userData['driver_license_expiration_date'] !== null) {
            $driverLicense = [
                'number'         => $userData['driver_license_number'],
                'expirationDate' => $userData['driver_license_expiration_date'],
                'documentUrl'    => $userData['driver_license_document_url'],
                'status'         => AuthService::driverLicenseStatus($userData['driver_license_expiration_date']),
                'uploadedAt'     => $userData['driver_license_uploaded_at'],
            ];
        }

        // Perfil profesional del mecánico (de su solicitud aprobada) y estado
        // de su última solicitud, para que la UI sepa si está en revisión.
        $applications = new MechanicApplicationService();
        $latestApplication = $applications->getByUser($userId)[0] ?? null;

        return [
            'id'                  => $userId,
            'email'               => $userData['email'],
            'firstName'           => $userData['first_name'],
            'lastName'            => $userData['last_name'],
            'idNumber'            => $userData['id_number'],
            'phone'               => $userData['phone'],
            'profilePictureUrl'   => $userData['profile_picture_url'],
            'accountStatus'       => $userData['account_status'],
            'createdAt'           => $userData['created_at'],
            'lastLoginAt'         => $userData['last_login_at'],
            'roles'               => $roles,
            'driverLicense'       => $driverLicense,
            'mechanicProfile'     => in_array('mechanic', $roles, true) ? $applications->getApprovedProfile($userId) : null,
            'mechanicApplication' => $latestApplication !== null ? [
                'id'          => (int)$latestApplication['id'],
                'status'      => $latestApplication['status'],
                'reviewNotes' => $latestApplication['review_notes'],
                'createdAt'   => $latestApplication['created_at'],
            ] : null,
        ];
    }

    /**
     * Actualizar perfil del usuario autenticado
     *
     * PUT /api/auth/profile
     *
     * Permite actualizar los datos personales (nombre, apellido, teléfono,
     * cédula, URL de la foto) y la licencia de conducción. La foto se sube
     * antes con POST /api/uploads y aquí se guarda su URL.
     *
     * @param Request $request HTTP request
     * @return Response HTTP response
     */
    public function profile(Request $request): Response
    {
        try {
            // Obtener usuario desde los atributos del request (establecido por AuthMiddleware)
            $user = $request->getAttribute('user');

            if ($user === null) {
                return ResponseFormatter::unauthorized('Autenticación requerida');
            }

            $userId = (int)$user['id'];

            // Parsear cuerpo JSON
            $jsonValidation = RequestValidator::parseJsonBody($request);
            if (!$jsonValidation['valid']) {
                return ResponseFormatter::error(
                    $jsonValidation['error'],
                    null,
                    $jsonValidation['statusCode']
                );
            }

            // Recopilar campos permitidos para actualización
            $updates = [];
            $now     = date('Y-m-d H:i:s');

            // Datos personales
            $personalLimits = ['first_name' => 100, 'last_name' => 100, 'phone' => 20];
            foreach ($personalLimits as $field => $max) {
                $value = $request->input($field);
                if ($value === null) {
                    continue;
                }
                $value = RequestValidator::sanitizeString((string)$value);
                if (($field !== 'phone' && $value === '') || mb_strlen($value) > $max) {
                    return ResponseFormatter::validationError([
                        $field => "El campo es requerido y no debe superar los {$max} caracteres"
                    ]);
                }
                $updates[$field] = $value === '' ? null : $value;
            }

            $idNumber = $request->input('id_number');
            if ($idNumber !== null) {
                $idNumber = RequestValidator::sanitizeString((string)$idNumber);
                if ($idNumber !== '' && !preg_match('/^\d{6,10}$/', $idNumber)) {
                    return ResponseFormatter::validationError([
                        'id_number' => 'La cédula debe tener entre 6 y 10 dígitos'
                    ]);
                }
                $updates['id_number'] = $idNumber === '' ? null : $idNumber;
            }

            $pictureUrl = $request->input('profile_picture_url');
            if ($pictureUrl !== null) {
                $pictureUrl = RequestValidator::sanitizeString((string)$pictureUrl);
                if ($pictureUrl !== '' && (mb_strlen($pictureUrl) > 500 || !UploadService::isUploadUrl($pictureUrl))) {
                    return ResponseFormatter::validationError([
                        'profile_picture_url' => 'La foto debe subirse primero con POST /api/uploads'
                    ]);
                }
                $updates['profile_picture_url'] = $pictureUrl === '' ? null : $pictureUrl;
            }

            // Número de licencia (users.driver_license_number VARCHAR(50))
            $licenseNumber = $request->input('driver_license_number');
            if ($licenseNumber !== null) {
                $licenseNumber = RequestValidator::sanitizeString($licenseNumber);

                if (strlen($licenseNumber) > 50) {
                    return ResponseFormatter::validationError([
                        'driver_license_number' => 'El número de licencia no debe superar los 50 caracteres'
                    ]);
                }

                $updates['driver_license_number'] = $licenseNumber;
                $updates['driver_license_uploaded_at'] = $now;
            }

            // Fecha de vencimiento (el estado vigente/por vencer/vencida se calcula al leer)
            $expirationDate = $request->input('driver_license_expiration_date');
            if ($expirationDate !== null) {
                $parsed = \DateTime::createFromFormat('Y-m-d', (string)$expirationDate);
                if (!$parsed || $parsed->format('Y-m-d') !== $expirationDate) {
                    return ResponseFormatter::validationError([
                        'driver_license_expiration_date' => 'El formato debe ser YYYY-MM-DD'
                    ]);
                }

                $updates['driver_license_expiration_date'] = $expirationDate;
                $updates['driver_license_uploaded_at'] = $now;
            }

            // URL del documento (users.driver_license_document_url VARCHAR(500))
            $documentUrl = $request->input('driver_license_document_url');
            if ($documentUrl !== null) {
                $documentUrl = RequestValidator::sanitizeString($documentUrl);

                if (strlen($documentUrl) > 500 || !filter_var($documentUrl, FILTER_VALIDATE_URL)) {
                    return ResponseFormatter::validationError([
                        'driver_license_document_url' => 'La URL del documento debe ser válida y no superar los 500 caracteres'
                    ]);
                }

                $updates['driver_license_document_url'] = $documentUrl;
            }

            // Si no hay nada que actualizar, retornar el perfil actual
            if (empty($updates)) {
                return $this->me($request);
            }

            // Aplicar actualización
            $this->authService->updateProfile($userId, $updates);

            // Retornar perfil actualizado
            return $this->me($request);

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Cambiar la contraseña del usuario autenticado
     *
     * PUT /api/auth/password
     *
     * Requiere la contraseña actual. Al cambiarla exitosamente, se cierran todas
     * las sesiones existentes (incluida la actual) y se emite una nueva sesión
     * para el dispositivo que hizo la solicitud, forzando el cierre de sesión en
     * cualquier otro dispositivo — comportamiento estándar tras un cambio de contraseña.
     *
     * @param Request $request Solicitud HTTP
     * @return Response Respuesta HTTP
     */
    public function changePassword(Request $request): Response
    {
        try {
            $user = $request->getAttribute('user');
            if ($user === null) {
                return ResponseFormatter::unauthorized('Autenticación requerida');
            }
            $userId = (int)$user['id'];

            $ipAddress = IPValidator::getClientIP($request);

            // Limitar tasa por IP para evitar fuerza bruta sobre la contraseña actual
            $rateLimitCheck = RateLimiter::check('change-password', $ipAddress);
            if (!$rateLimitCheck['allowed']) {
                $retryAfter = $rateLimitCheck['reset_at'] - time();
                return ResponseFormatter::rateLimitExceeded($retryAfter);
            }

            $jsonValidation = RequestValidator::parseJsonBody($request);
            if (!$jsonValidation['valid']) {
                return ResponseFormatter::error(
                    $jsonValidation['error'],
                    null,
                    $jsonValidation['statusCode']
                );
            }

            $validation = RequestValidator::validateChangePasswordRequest($request);
            if (!$validation['valid']) {
                return ResponseFormatter::validationError($validation['errors']);
            }

            $currentPassword = $request->input('current_password');
            $newPassword     = $request->input('new_password');

            if (!$this->authService->verifyCurrentPassword($userId, $currentPassword)) {
                RateLimiter::recordAttempt('change-password', $ipAddress);
                throw new DomainException('La contraseña actual es incorrecta', 400);
            }

            RateLimiter::reset('change-password', $ipAddress);

            $newHash = $this->passwordHasher->hash($newPassword);
            $this->authService->updatePassword($userId, $newHash);

            // Cerrar todas las sesiones existentes y emitir una nueva para este dispositivo
            $this->sessionManager->destroyAllUserSessions($userId);
            $sessionId = $this->sessionManager->create($userId, [
                'ip_address' => $ipAddress,
                'user_agent' => $request->userAgent(),
                'remember'   => false
            ]);

            $response = ResponseFormatter::success(
                null,
                'Contraseña actualizada correctamente. Se cerró la sesión en tus otros dispositivos.',
                200
            );

            ResponseFormatter::setSessionCookie($response, $sessionId, false);

            return $response;

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Solicita un enlace de recuperación de contraseña por correo
     *
     * POST /api/auth/forgot-password
     *
     * Siempre responde con el mismo mensaje genérico exista o no la cuenta
     * (mismo criterio anti-enumeración que login) — solo el correo real,
     * si la cuenta existe, contiene información que la distingue.
     *
     * @param Request $request HTTP request
     * @return Response HTTP response
     */
    public function forgotPassword(Request $request): Response
    {
        try {
            $ipAddress = IPValidator::getClientIP($request);

            $rateLimitCheck = RateLimiter::check('forgot-password', $ipAddress);
            if (!$rateLimitCheck['allowed']) {
                $retryAfter = $rateLimitCheck['reset_at'] - time();
                return ResponseFormatter::rateLimitExceeded($retryAfter);
            }
            RateLimiter::recordAttempt('forgot-password', $ipAddress);

            $jsonValidation = RequestValidator::parseJsonBody($request);
            if (!$jsonValidation['valid']) {
                return ResponseFormatter::error(
                    $jsonValidation['error'],
                    null,
                    $jsonValidation['statusCode']
                );
            }

            $validation = RequestValidator::validateForgotPasswordRequest($request);
            if (!$validation['valid']) {
                return ResponseFormatter::validationError($validation['errors']);
            }

            $this->passwordResetService->requestReset($request->input('email'), $ipAddress);

            return ResponseFormatter::success(
                null,
                'Si el correo corresponde a una cuenta registrada, recibirás un enlace de recuperación en unos minutos.',
                200
            );

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Restablece la contraseña usando un token de recuperación válido
     *
     * POST /api/auth/reset-password
     *
     * @param Request $request HTTP request
     * @return Response HTTP response
     */
    public function resetPassword(Request $request): Response
    {
        try {
            $ipAddress = IPValidator::getClientIP($request);

            $rateLimitCheck = RateLimiter::check('reset-password', $ipAddress);
            if (!$rateLimitCheck['allowed']) {
                $retryAfter = $rateLimitCheck['reset_at'] - time();
                return ResponseFormatter::rateLimitExceeded($retryAfter);
            }

            $jsonValidation = RequestValidator::parseJsonBody($request);
            if (!$jsonValidation['valid']) {
                return ResponseFormatter::error(
                    $jsonValidation['error'],
                    null,
                    $jsonValidation['statusCode']
                );
            }

            $validation = RequestValidator::validateResetPasswordRequest($request);
            if (!$validation['valid']) {
                return ResponseFormatter::validationError($validation['errors']);
            }

            try {
                $this->passwordResetService->resetPassword(
                    $request->input('token'),
                    $request->input('new_password')
                );
            } catch (DomainException $e) {
                RateLimiter::recordAttempt('reset-password', $ipAddress);
                throw $e;
            }

            RateLimiter::reset('reset-password', $ipAddress);

            return ResponseFormatter::success(
                null,
                'Tu contraseña fue restablecida correctamente. Ya puedes iniciar sesión con tu nueva contraseña.',
                200
            );

        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Health check endpoint
     *
     * GET /api/auth/health
     *
     * Requirements: 14.1-14.7
     *
     * @param Request $request HTTP request
     * @return Response HTTP response
     */
    public function health(Request $request): Response
    {
        $startTime = microtime(true);

        try {
            // Test database connectivity
            if (!$this->authService->checkDatabaseHealth()) {
                throw new \Exception('Database health check failed');
            }

            // Calculate response time in milliseconds
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            // Prepare response data
            $responseData = [
                'status' => 'healthy',
                'version' => '1.0.0',
                'timestamp' => date('Y-m-d H:i:s'),
                'responseTime' => $responseTime
            ];

            return ResponseFormatter::success($responseData, 'Service is healthy', 200);

        } catch (\Exception $e) {
            // Calculate response time even on failure
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            // Prepare unhealthy response data
            $responseData = [
                'status' => 'unhealthy',
                'version' => '1.0.0',
                'timestamp' => date('Y-m-d H:i:s'),
                'responseTime' => $responseTime
            ];

            // Log exception via ErrorHandler
            ErrorHandler::logException($e);

            return ResponseFormatter::error(
                'Service is unhealthy',
                $responseData,
                503
            );
        }
    }
}
