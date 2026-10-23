<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Models\Auth\SessionManager;
use App\Models\Auth\RoleValidator;
use App\Core\Http\ResponseFormatter;
use App\Core\Http\IPValidator;

/**
 * Middleware de Autenticación
 *
 * Valida la autenticación de sesión para rutas protegidas extrayendo la cookie de sesión,
 * validando la sesión mediante SessionManager, obteniendo los datos del usuario y
 * adjuntándolos a la solicitud.
 *
 * Requisitos: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6, 10.7
 */
class AuthMiddleware
{
    private SessionManager $sessionManager;
    private RoleValidator $roleValidator;

    public function __construct()
    {
        $this->sessionManager = new SessionManager();
        $this->roleValidator = new RoleValidator();
    }

    /**
     * Procesa la solicitud entrante
     *
     * Valida la autenticación e implementa la regeneración automática de sesión
     * para protección contra fijación de sesión.
     *
     * Requisitos: 10.1-10.7
     *
     * @param Request $request Objeto de solicitud HTTP
     * @param callable $next Siguiente middleware/controlador en la cadena
     * @return Response Respuesta HTTP
     */
    public function handle(Request $request, callable $next): Response
    {
        // Requisito 10.1: Extraer el ID de sesión desde la cookie
        $sessionId = $request->cookie(ResponseFormatter::getSessionCookieName());

        // Requisito 10.2: Retornar 401 si no hay cookie de sesión presente
        if ($sessionId === null || empty($sessionId)) {
            return ResponseFormatter::unauthorized('Debes iniciar sesión');
        }

        // Verificar si la sesión debe regenerarse por seguridad (anti-fijación) ANTES
        // de validar: validate() actualiza last_activity a "ahora" en cada llamada, así
        // que si este chequeo se hiciera después, el intervalo transcurrido medido
        // sería siempre ~0 y la regeneración periódica nunca se dispararía bajo uso
        // continuo — dejando la protección anti-fijación efectivamente inactiva.
        $shouldRegenerate = $this->sessionManager->shouldRegenerate($sessionId);

        // Requisito 10.3, 20.7: Validar sesión mediante SessionManager con detección de cambio de IP
        $currentIP = IPValidator::getClientIP($request);
        $sessionData = $this->sessionManager->validate($sessionId, $currentIP);

        // Requisito 10.4: Retornar 401 si la sesión es inválida
        if ($sessionData === null) {
            return ResponseFormatter::unauthorized('Tu sesión expiró. Inicia sesión de nuevo');
        }

        if ($shouldRegenerate) {
            // Regenerar el ID de sesión
            $newSessionId = $this->sessionManager->regenerate($sessionId);

            if (!empty($newSessionId)) {
                // Actualizar el ID de sesión en el contexto de la solicitud
                $sessionId = $newSessionId;

                // Nota: la nueva cookie se establecerá en la respuesta después de llamar a $next()
                // Almacenar indicador para la modificación de la respuesta
                $request->setAttribute('session_regenerated', true);
                $request->setAttribute('new_session_id', $newSessionId);
            }
        }

        // Requisito 10.5: Obtener los datos del usuario desde la base de datos
        try {
            $user = Database::fetchOne(
                'SELECT id, email, first_name, last_name, account_status, last_login_at, created_at
                 FROM users
                 WHERE id = ? AND deleted_at IS NULL',
                [$sessionData->userId]
            );

            // Retornar 401 si el usuario no existe o fue eliminado
            if ($user === null) {
                return ResponseFormatter::unauthorized('Usuario no encontrado');
            }

            // Verificar si la cuenta está activa
            if ($user['account_status'] !== 'active') {
                return ResponseFormatter::forbidden('Tu cuenta no está activa');
            }

            // Requisito 10.5, 10.6: Adjuntar SessionData y datos del usuario a la solicitud
            $request->setAttribute('session', $sessionData);
            $request->setAttribute('user', $user);
            $request->setAttribute('userId', (int)$user['id']);

            // Obtener los roles del usuario y determinar el rol principal
            $userRoles = $this->roleValidator->getUserRoles((int)$user['id']);
            $primaryRole = $this->determinePrimaryRole($userRoles);

            // Adjuntar roles a la solicitud para RBAC y autorización
            $request->setAttribute('userRoles', $userRoles);  // Arreglo con todos los roles activos
            $request->setAttribute('userRole', $primaryRole); // Rol principal para decisiones de rol único

            // Requisito 10.7: Continuar hacia el siguiente middleware/controlador
            $response = $next($request);

            // Si la sesión fue regenerada, actualizar la cookie en la respuesta
            if ($request->getAttribute('session_regenerated') === true) {
                $newSessionId = $request->getAttribute('new_session_id');
                ResponseFormatter::setSessionCookie($response, $newSessionId, false);
            }

            return $response;

        } catch (\Exception $e) {
            // Registrar el error
            error_log("AuthMiddleware error: " . $e->getMessage());

            return ResponseFormatter::serverError('El servicio de autenticación no está disponible');
        }
    }

    /**
     * Determina el rol principal a partir de un arreglo de roles
     *
     * Usa prioridad jerárquica para seleccionar el rol más privilegiado
     * cuando un usuario tiene múltiples roles activos.
     *
     * Orden de prioridad (de mayor a menor):
     * 1. super_admin     - Acceso total al sistema
     * 2. administrator   - Acceso administrativo
     * 3. mechanic        - Proveedor de servicios
     * 4. customer        - Usuario estándar
     * 5. support         - Solo lectura
     *
     * @param array $roles Arreglo de slugs de roles
     * @return string Slug del rol principal (por defecto 'customer' si no hay roles)
     */
    private function determinePrimaryRole(array $roles): string
    {
        if (empty($roles)) {
            return 'customer'; // Por defecto 'customer' si no se han asignado roles
        }

        // Prioridad de roles (ordenada de mayor a menor privilegio)
        $rolePriority = [
            'super_admin',
            'administrator',
            'mechanic',
            'customer',
            'support'
        ];

        // Retornar el primer rol encontrado en orden de prioridad
        foreach ($rolePriority as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        // Si no se reconoce ningún rol, retornar el primero alfabéticamente
        return $roles[0];
    }
}
