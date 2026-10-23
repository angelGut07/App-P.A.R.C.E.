<?php

namespace App\Models\Auth;

use App\Core\Database;

/**
 * Servicio de Validación de Roles
 *
 * Proporciona funcionalidad de validación y verificación de roles para el control de acceso basado en roles (RBAC).
 * Consulta las tablas user_roles y roles con filtrado de roles activos,
 * verificación de expiración y caché por solicitud para mejorar el rendimiento.
 *
 * Requisitos: Sección de Integración de Diseño RBAC
 */
class RoleValidator
{
    /**
     * Caché de roles para evitar consultas repetidas a la base de datos dentro de una misma solicitud
     *
     * @var array<int, array<string>>
     */
    private array $roleCache = [];

    /**
     * Obtiene todos los roles activos de un usuario
     *
     * Obtiene roles de la tabla user_roles con los siguientes filtros:
     * - Solo roles activos (is_active = true)
     * - Roles no expirados (expires_at IS NULL OR expires_at > NOW())
     * - Definiciones de roles activos (roles.is_active = true)
     *
     * @param int $userId ID del usuario
     * @return array Arreglo de slugs de roles
     */
    public function getUserRoles(int $userId): array
    {
        // Verificar caché primero (caché por solicitud, no estático)
        if (isset($this->roleCache[$userId])) {
            return $this->roleCache[$userId];
        }

        try {
            $roles = Database::fetchAll(
                'SELECT r.slug
                 FROM user_roles ur
                 JOIN roles r ON ur.role_id = r.id
                 WHERE ur.user_id = ?
                   AND ur.is_active = TRUE
                   AND r.is_active = TRUE
                   AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                 ORDER BY r.slug',
                [$userId]
            );

            // Extraer slugs del resultado
            $slugs = array_map(function($role) {
                return $role['slug'];
            }, $roles);

            // Almacenar en caché el resultado (por solicitud, no estático)
            $this->roleCache[$userId] = $slugs;

            return $slugs;

        } catch (\Exception $e) {
            error_log("RoleValidator::getUserRoles error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Verifica si el usuario tiene un rol específico
     *
     * @param int $userId ID del usuario
     * @param string $roleSlug Slug del rol a verificar
     * @return bool Verdadero si el usuario tiene el rol, falso en caso contrario
     */
    public function hasRole(int $userId, string $roleSlug): bool
    {
        $roles = $this->getUserRoles($userId);
        return in_array($roleSlug, $roles, true);
    }

    /**
     * Verifica si el usuario tiene al menos uno de los roles especificados
     *
     * @param int $userId ID del usuario
     * @param array $roleSlugs Arreglo de slugs de roles a verificar
     * @return bool Verdadero si el usuario tiene al menos un rol, falso en caso contrario
     */
    public function hasAnyRole(int $userId, array $roleSlugs): bool
    {
        if (empty($roleSlugs)) {
            return false;
        }

        $userRoles = $this->getUserRoles($userId);

        foreach ($roleSlugs as $roleSlug) {
            if (in_array($roleSlug, $userRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica si el usuario tiene todos los roles especificados
     *
     * @param int $userId ID del usuario
     * @param array $roleSlugs Arreglo de slugs de roles a verificar
     * @return bool Verdadero si el usuario tiene todos los roles, falso en caso contrario
     */
    public function hasAllRoles(int $userId, array $roleSlugs): bool
    {
        if (empty($roleSlugs)) {
            return false;
        }

        $userRoles = $this->getUserRoles($userId);

        foreach ($roleSlugs as $roleSlug) {
            if (!in_array($roleSlug, $userRoles, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Limpia la caché de roles para un usuario específico
     *
     * Útil cuando los roles son modificados y la caché necesita ser invalidada
     *
     * @param int $userId ID del usuario
     * @return void
     */
    public function clearCache(int $userId): void
    {
        unset($this->roleCache[$userId]);
    }

    /**
     * Limpia todas las cachés de roles
     *
     * @return void
     */
    public function clearAllCaches(): void
    {
        $this->roleCache = [];
    }
}
