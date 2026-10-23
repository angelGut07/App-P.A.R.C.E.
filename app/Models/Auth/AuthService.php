<?php

namespace App\Models\Auth;

use App\Core\Database;
use App\Core\DatabaseException;
use App\Core\DomainException;
use App\Models\Auth\AuthResult;
use App\Models\Auth\SessionData;
use App\Models\Auth\AuthenticationException;

/**
 * Servicio de Autenticación
 *
 * Servicio principal de autenticación que gestiona el inicio de sesión, cierre de sesión,
 * validación de sesiones y verificación de credenciales con protección contra ataques de tiempo.
 *
 * Requisitos: 3.1-3.7, 14.1-14.4, 15.1-15.4, 16.1-16.4, 18.1-18.5, 20.1-20.4, 24.1-24.4
 */
class AuthService
{
    private PasswordHasher $passwordHasher;
    private SessionManager $sessionManager;
    private ?SessionData $currentSession = null;
    private ?array $currentUser = null;

    /**
     * Crea una nueva instancia de AuthService
     *
     * @param PasswordHasher $passwordHasher Servicio de hash de contraseñas
     * @param SessionManager $sessionManager Servicio de gestión de sesiones
     */
    public function __construct(
        PasswordHasher $passwordHasher,
        SessionManager $sessionManager
    ) {
        $this->passwordHasher = $passwordHasher;
        $this->sessionManager = $sessionManager;
    }

    /**
     * Autentica al usuario con correo electrónico y contraseña
     *
     * Requisitos: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 16.1, 16.2, 16.3, 16.4,
     *               18.1, 18.2, 18.3, 18.4, 20.1, 20.2, 24.1, 24.2, 24.3, 24.4
     *
     * @param string $email Dirección de correo electrónico del usuario
     * @param string $password Contraseña en texto plano
     * @param bool $remember Habilitar la funcionalidad "recordarme"
     * @param string $ipAddress Dirección IP del cliente para registro y seguimiento de sesión
     * @param string $userAgent Agente de usuario del cliente para seguimiento de sesión
     * @return AuthResult Resultado de autenticación con datos de sesión
     */
    public function authenticate(
        string $email,
        string $password,
        bool $remember = false,
        string $ipAddress = '0.0.0.0',
        string $userAgent = ''
    ): AuthResult {
        // Requisito 18.2: Validar el formato del correo electrónico
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return AuthResult::failure('Correo o contraseña incorrectos');
        }

        // Requisito 18.3: Validar que la contraseña no esté vacía
        if (empty($password) || strlen($password) < 8) {
            return AuthResult::failure('Correo o contraseña incorrectos');
        }

        try {
            // Requisito 3.1: Obtener el usuario de la base de datos por correo electrónico
            $user = Database::fetchOne(
                'SELECT id, email, password_hash, account_status
                 FROM users
                 WHERE email = ? AND deleted_at IS NULL',
                [$email]
            );

            // Requisito 3.3, 24.1, 24.3: Manejar el caso de usuario no encontrado (seguro contra tiempos)
            if ($user === null) {
                // Requisito 24.1: Realizar un hash ficticio para prevenir ataques de tiempo
                $this->passwordHasher->hash('dummy_password_for_timing_safety_' . bin2hex(random_bytes(8)));
                // Requisito 18.4, 20.2: Mensaje de error genérico (sin enumeración de usuarios) + registrar IP
                error_log("Authentication failed: User not found for email {$email} from IP {$ipAddress}");
                return AuthResult::failure('Correo o contraseña incorrectos');
            }

            // Requisito 3.4: Verificar el estado de la cuenta
            if ($user['account_status'] !== 'active') {
                error_log("Authentication failed: Inactive account for email {$email} from IP {$ipAddress}");
                return AuthResult::failure('Tu cuenta no está activa');
            }

            // Requisito 3.5: Verificar la contraseña usando PasswordHasher
            $isValid = $this->passwordHasher->verify($password, $user['password_hash']);

            // Requisito 3.7: Si la contraseña es inválida, retornar fallo
            if (!$isValid) {
                error_log("Authentication failed: Invalid password for email {$email} from IP {$ipAddress}");
                return AuthResult::failure('Correo o contraseña incorrectos');
            }

            // Requisito 16.1, 16.2: Verificar si la contraseña necesita ser rehasheada
            if ($this->passwordHasher->needsRehash($user['password_hash'])) {
                // Requisito 16.3, 16.4: Rehashear la contraseña de forma transparente
                $newHash = $this->passwordHasher->hash($password);
                Database::update('users', [
                    'password_hash' => $newHash
                ], 'id = ?', [$user['id']]);
            }

            // Requisito 3.5: Crear sesión usando SessionManager
            $sessionId = $this->sessionManager->create((int)$user['id'], [
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'remember' => $remember
            ]);

            // Requisito 3.6, 20.2: Actualizar la marca de tiempo del último inicio de sesión y la dirección IP
            Database::update('users', [
                'last_login_at' => date('Y-m-d H:i:s'),
                'last_login_ip' => $ipAddress
            ], 'id = ?', [$user['id']]);

            // Registrar autenticación exitosa con IP
            error_log("Authentication successful: User {$user['id']} ({$email}) logged in from IP {$ipAddress}");

            // Requisito 3.1: Retornar éxito con userId y sessionId
            return AuthResult::success((int)$user['id'], $sessionId);

        } catch (AuthenticationException $e) {
            // Requisito 18.5: Registrar fallos de autenticación
            error_log("Authentication failed for email {$email}: " . $e->getMessage());
            return AuthResult::failure('Authentication error occurred');
        } catch (\Exception $e) {
            // Requisito 18.1: Manejar fallos de conexión a la base de datos de forma elegante
            error_log("Authentication error for email {$email}: " . $e->getMessage());
            return AuthResult::failure('El servicio de autenticación no está disponible');
        }
    }

    /**
     * Cierra la sesión del usuario destruyendo la sesión
     *
     * Requisitos: 15.1, 15.2, 15.3, 15.4
     *
     * @param string $sessionId ID de sesión a destruir
     * @return bool Verdadero si la sesión fue destruida, falso en caso contrario
     */
    public function logout(string $sessionId): bool
    {
        // Requisito 15.1, 15.4: Destruir la sesión mediante SessionManager
        $destroyed = $this->sessionManager->destroy($sessionId);

        // Limpiar los datos de sesión en caché
        $this->currentSession = null;
        $this->currentUser = null;

        // Requisito 15.2, 15.3: Retornar verdadero si fue destruida, falso si es inválida
        return $destroyed;
    }

    /**
     * Valida la sesión y retorna los datos de sesión
     *
     * Requisitos: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6
     *
     * @param string $sessionId ID de sesión a validar
     * @return SessionData|null Datos de sesión si es válida, nulo en caso contrario
     */
    public function validateSession(string $sessionId): ?SessionData
    {
        // Delegar al SessionManager
        return $this->sessionManager->validate($sessionId);
    }

    /**
     * Verifica si el usuario está autenticado actualmente
     *
     * Requisitos: 14.1, 14.2
     *
     * @return bool Verdadero si está autenticado, falso en caso contrario
     */
    public function isAuthenticated(): bool
    {
        // Verificar si tenemos una sesión en caché
        if ($this->currentSession !== null) {
            return true;
        }

        // Intentar obtener la sesión desde la cookie
        $sessionId = $_COOKIE['parce_session'] ?? null;
        if ($sessionId === null) {
            return false;
        }

        // Validar la sesión
        $this->currentSession = $this->sessionManager->validate($sessionId);

        // Requisito 14.1: Retornar verdadero si existe una sesión válida
        // Requisito 14.2: Retornar falso si no existe sesión
        return $this->currentSession !== null;
    }

    /**
     * Obtiene los datos del usuario autenticado actualmente
     *
     * Requisitos: 14.3, 14.4
     *
     * @return array|null Datos del usuario si está autenticado, nulo en caso contrario
     */
    public function getCurrentUser(): ?array
    {
        // Retornar el usuario en caché si está disponible
        if ($this->currentUser !== null) {
            return $this->currentUser;
        }

        // Verificar si está autenticado
        if (!$this->isAuthenticated()) {
            // Requisito 14.4: Retornar nulo si no existe sesión
            return null;
        }

        // Requisito 14.3: Obtener los datos del usuario desde la base de datos
        $this->currentUser = Database::fetchOne(
            'SELECT id, email, first_name, last_name, account_status, last_login_at
             FROM users
             WHERE id = ? AND deleted_at IS NULL',
            [$this->currentSession->userId]
        );

        return $this->currentUser;
    }

    /**
     * Actualiza la marca de tiempo de actividad de la sesión
     *
     * Requisitos: 20.1, 20.2, 20.3, 20.4
     *
     * @param string $sessionId ID de sesión a refrescar
     * @return bool Verdadero si fue refrescada, falso si la sesión no existe
     */
    public function refreshSession(string $sessionId): bool
    {
        // Requisito 20.1, 20.4: Actualizar last_activity mediante validate()
        $sessionData = $this->sessionManager->validate($sessionId);

        // Requisito 20.2: Retornar falso si la sesión no existe
        // Requisito 20.3: Retornar verdadero si la sesión fue refrescada
        return $sessionData !== null;
    }

    /**
     * Verifica si ya existe un usuario activo con el correo indicado.
     *
     * @param string $email Correo electrónico a verificar
     * @return bool Verdadero si ya existe un usuario no eliminado con ese correo
     */
    public function emailExists(string $email): bool
    {
        $existing = Database::fetchOne(
            'SELECT id FROM users WHERE email = ? AND deleted_at IS NULL',
            [$email]
        );

        return $existing !== null;
    }

    /**
     * Registra un nuevo usuario: crea la fila en `users`, le asigna el rol
     * `customer`, registra (si vienen) su vehículo y su solicitud de mecánico,
     * y crea su primera sesión — todo en una única transacción.
     *
     * El registro público SOLO asigna el rol 'customer'. Si el usuario pidió
     * ser mecánico se abre una solicitud en `mechanic_applications`; el rol
     * 'mechanic' únicamente lo asigna MechanicApplicationService::approve()
     * cuando un administrador la aprueba.
     *
     * @param array       $profile             first_name, last_name, phone, id_number, driver_license_number
     * @param array|null  $vehicle             Datos del vehículo (claves de VehicleService::create()) o null
     * @param array|null  $mechanicApplication Datos de la solicitud de mecánico o null
     * @return AuthResult Resultado con userId y sessionId del usuario recién creado
     */
    public function register(
        string $email,
        string $passwordHash,
        array $profile,
        string $ipAddress,
        string $userAgent,
        ?array $vehicle = null,
        ?array $mechanicApplication = null
    ): AuthResult {
        Database::beginTransaction();

        try {
            // AuthController::register() ya pre-verifica el email con emailExists()
            // antes de llegar aquí, pero eso es "check then insert" — no es atómico
            // por sí solo. users.email tiene un UNIQUE a nivel de BD que sí cierra la
            // carrera ante dos registros concurrentes con el mismo email; se traduce
            // a la misma respuesta 409 amigable en vez de dejarla propagar como 500.
            try {
                $userId = Database::insert('users', [
                    'email'                      => $email,
                    'password_hash'              => $passwordHash,
                    'first_name'                 => $profile['first_name'],
                    'last_name'                  => $profile['last_name'],
                    'phone'                      => $profile['phone'] ?? null,
                    'id_number'                  => $profile['id_number'] ?? null,
                    'driver_license_number'      => $profile['driver_license_number'] ?? null,
                    'driver_license_uploaded_at' => !empty($profile['driver_license_number']) ? date('Y-m-d H:i:s') : null,
                    'account_status'             => 'active',
                ]);
            } catch (DatabaseException $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry')) {
                    $field = str_contains($e->getMessage(), 'id_number') ? 'cédula' : 'correo electrónico';
                    throw new DomainException("Ya existe una cuenta con este {$field}", 409);
                }
                throw $e;
            }

            // El registro público SOLO asigna el rol 'customer' — resuelto aquí
            // internamente, nunca desde un parámetro del método ni desde la
            // petición HTTP.
            $role = Database::fetchOne(
                'SELECT id FROM roles WHERE slug = ? AND is_active = TRUE',
                ['customer']
            );

            if ($role === null) {
                throw new \Exception("Role 'customer' not found");
            }

            // Asignar el rol 'customer'
            Database::insert('user_roles', [
                'user_id' => $userId,
                'role_id' => $role['id'],
                'assigned_at' => date('Y-m-d H:i:s'),
                'is_active' => true
            ]);

            if ($vehicle !== null) {
                $plateTaken = Database::fetchOne(
                    'SELECT id FROM vehicles WHERE license_plate = ? AND deleted_at IS NULL',
                    [strtoupper($vehicle['license_plate'])]
                );
                if ($plateTaken !== null) {
                    throw new DomainException('Ya existe un vehículo con esta placa', 409);
                }

                $vehicleId = Database::insert('vehicles', [
                    'user_id'       => $userId,
                    'license_plate' => strtoupper($vehicle['license_plate']),
                    'make'          => $vehicle['make'],
                    'model'         => $vehicle['model'],
                    'year'          => (int)$vehicle['year'],
                    'color'         => $vehicle['color'] ?? null,
                    'is_primary'    => true,
                    'status'        => 'active',
                ]);
                (new \App\Models\Vehicle\VehicleService())->saveDocuments($vehicleId, $vehicle);
            }

            if ($mechanicApplication !== null) {
                (new \App\Models\MechanicApplication\MechanicApplicationService())
                    ->createWithinTransaction($userId, $mechanicApplication);
            }

            $sessionId = $this->sessionManager->create($userId, [
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'remember' => false
            ]);

            Database::commit();

            return AuthResult::success($userId, $sessionId);

        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Obtiene los datos crudos de perfil de un usuario (fila de `users`,
     * incluidos los campos de licencia de conducción).
     *
     * @param int $userId ID del usuario
     * @return array|null Fila de la tabla `users`, o null si no existe
     */
    public function getUserProfileData(int $userId): ?array
    {
        return Database::fetchOne(
            'SELECT id, email, first_name, last_name, id_number, phone, profile_picture_url,
                    account_status, last_login_at, created_at,
                    driver_license_number, driver_license_expiration_date,
                    driver_license_document_url, driver_license_uploaded_at
             FROM users
             WHERE id = ? AND deleted_at IS NULL',
            [$userId]
        );
    }

    /**
     * Aplica actualizaciones al perfil de un usuario (datos personales, foto
     * y licencia de conducción).
     *
     * @param int   $userId  ID del usuario
     * @param array $updates Columnas a actualizar
     * @return void
     * @throws DomainException 409 si la cédula ya pertenece a otra cuenta
     */
    public function updateProfile(int $userId, array $updates): void
    {
        try {
            Database::update('users', $updates, 'id = ?', [$userId]);
        } catch (DatabaseException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                throw new DomainException('Ya existe una cuenta con esta cédula', 409);
            }
            throw $e;
        }
    }

    /**
     * Estado de una licencia según su fecha de vencimiento. Se calcula al
     * leer (no se guarda) para que nunca quede desactualizado.
     *
     * @return string not_set | valid | expiring_soon | expired
     */
    public static function driverLicenseStatus(?string $expirationDate): string
    {
        if ($expirationDate === null || $expirationDate === '') {
            return 'not_set';
        }

        $today  = new \DateTime('today');
        $expiry = new \DateTime($expirationDate);

        if ($expiry < $today) {
            return 'expired';
        }

        return (int)$today->diff($expiry)->days <= 30 ? 'expiring_soon' : 'valid';
    }

    /**
     * Verifica la contraseña actual de un usuario.
     *
     * @param int    $userId       ID del usuario
     * @param string $plainPassword Contraseña en texto plano a verificar
     * @return bool Verdadero si el usuario existe y la contraseña coincide
     */
    public function verifyCurrentPassword(int $userId, string $plainPassword): bool
    {
        $userRecord = Database::fetchOne(
            'SELECT password_hash FROM users WHERE id = ? AND deleted_at IS NULL',
            [$userId]
        );

        if ($userRecord === null) {
            return false;
        }

        return $this->passwordHasher->verify($plainPassword, $userRecord['password_hash']);
    }

    /**
     * Actualiza el hash de contraseña de un usuario.
     *
     * @param int    $userId          ID del usuario
     * @param string $newPasswordHash Nuevo hash de contraseña (ya calculado)
     * @return void
     */
    public function updatePassword(int $userId, string $newPasswordHash): void
    {
        Database::update('users', [
            'password_hash' => $newPasswordHash,
            'updated_at'    => date('Y-m-d H:i:s'),
        ], 'id = ?', [$userId]);
    }

    /**
     * Verifica conectividad básica con la base de datos.
     *
     * @return bool Verdadero si la base de datos respondió correctamente
     */
    public function checkDatabaseHealth(): bool
    {
        $result = Database::fetchOne('SELECT 1 as test');

        return $result !== null && $result['test'] === 1;
    }
}
