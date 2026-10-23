<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Models\Auth\RoleValidator;
use App\Core\Http\ResponseFormatter;

/**
 * Middleware RBAC (Control de Acceso Basado en Roles)
 *
 * Aplica control de acceso basado en roles para rutas protegidas.
 * Verifica que el usuario autenticado tenga al menos uno de los roles requeridos.
 *
 * Requisitos: Componente de Diseño 3 (RBACMiddleware)
 */
class RBACMiddleware
{
    private RoleValidator $roleValidator;
    private array $allowedRoles;

    /**
     * Crea una instancia del middleware RBAC
     *
     * @param array $allowedRoles Arreglo de slugs de roles con permiso para acceder a la ruta
     */
    public function __construct(array $allowedRoles)
    {
        $this->allowedRoles = $allowedRoles;
        $this->roleValidator = new RoleValidator();
    }

    /**
     * Procesa la solicitud entrante
     *
     * Requisitos: Componente de Diseño 3
     *
     * @param Request $request Objeto de solicitud HTTP
     * @param callable $next Siguiente middleware/controlador en la cadena
     * @return Response Respuesta HTTP
     */
    public function handle(Request $request, callable $next): Response
    {
        // Obtener el usuario autenticado desde los atributos de la solicitud (asignado por AuthMiddleware)
        $user = $request->getAttribute('user');

        // Retornar 401 si el usuario no está adjunto (AuthMiddleware debe haber ejecutado primero)
        if ($user === null) {
            return ResponseFormatter::unauthorized('Debes iniciar sesión');
        }

        $userId = (int)$user['id'];

        // Obtener los roles activos del usuario mediante RoleValidator
        $userRoles = $this->roleValidator->getUserRoles($userId);

        // Verificar si el usuario tiene al menos uno de los roles requeridos
        $hasRequiredRole = $this->roleValidator->hasAnyRole($userId, $this->allowedRoles);

        // Retornar 403 Forbidden si el usuario no tiene el rol requerido
        if (!$hasRequiredRole) {
            return ResponseFormatter::forbidden('Permisos insuficientes para acceder a este recurso');
        }

        // Permitir que la solicitud continúe si el usuario tiene el rol requerido
        return $next($request);
    }

    /**
     * Obtiene los roles permitidos para esta instancia del middleware
     *
     * @return array Arreglo de slugs de roles permitidos
     */
    public function getAllowedRoles(): array
    {
        return $this->allowedRoles;
    }
}
