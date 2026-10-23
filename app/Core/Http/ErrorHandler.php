<?php

namespace App\Core\Http;

use App\Core\Response;
use App\Core\DomainException;
use App\Core\RequestContext;
use App\Models\Auth\AuthenticationException;

/**
 * Manejador de Errores
 *
 * Gestiona el mapeo de excepciones a códigos de estado HTTP, el registro de errores y
 * la generación estandarizada de respuestas de error. Previene la divulgación de información
 * retornando mensajes de error genéricos al cliente mientras registra los detalles completos.
 *
 * Requisitos: 11.1, 11.2, 11.3, 11.4, 11.5, 11.6, 11.7
 */
class ErrorHandler
{
    /**
     * Maneja una excepción y retorna una respuesta de error estandarizada
     *
     * Requisitos: 11.1, 11.5, 11.6, 11.7
     *
     * @param \Throwable $e Excepción a manejar
     * @return Response Respuesta de error estandarizada
     */
    public static function handleException(\Throwable $e): Response
    {
        // Registrar los detalles completos de la excepción para depuración
        self::logException($e);

        // Mapear la excepción al código de estado HTTP
        $statusCode = self::mapExceptionToStatusCode($e);

        // Las DomainException e InvalidArgumentException son violaciones de reglas de
        // negocio o de validación esperadas y seguras de mostrar al usuario (ej. "Ya
        // existe un vehículo con esta placa"); el resto de excepciones se enmascaran
        // con un mensaje genérico para no filtrar detalles internos (consultas SQL,
        // rutas de archivos, etc.)
        $message = ($e instanceof DomainException || $e instanceof \InvalidArgumentException)
            ? $e->getMessage()
            : self::getGenericErrorMessage($statusCode);

        // Retornar la respuesta de error estandarizada
        return ResponseFormatter::error($message, null, $statusCode);
    }

    /**
     * Mapea una excepción al código de estado HTTP correspondiente
     *
     * Requisito 11.7: Mapear AuthenticationException a HTTP 401
     *
     * @param \Throwable $e Excepción
     * @return int Código de estado HTTP
     */
    public static function mapExceptionToStatusCode(\Throwable $e): int
    {
        // DomainException → código de estado que trae consigo la excepción
        if ($e instanceof DomainException) {
            return $e->getStatusCode();
        }

        // AuthenticationException → 401
        if ($e instanceof AuthenticationException) {
            return 401;
        }

        // InvalidArgumentException → 400
        if ($e instanceof \InvalidArgumentException) {
            return 400;
        }

        // PDOException → 500
        if ($e instanceof \PDOException) {
            return 500;
        }

        // Predeterminado → 500
        return 500;
    }

    /**
     * Obtiene el mensaje de error genérico para un código de estado
     *
     * Requisito 11.6: Retornar mensaje de error genérico (sin trazas de pila)
     *
     * @param int $statusCode Código de estado HTTP
     * @return string Mensaje de error genérico
     */
    public static function getGenericErrorMessage(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Bad request',
            401 => 'No autorizado',
            403 => 'Acceso denegado',
            404 => 'Recurso no encontrado',
            409 => 'Conflict',
            429 => 'Demasiados intentos. Intenta de nuevo más tarde',
            500 => 'Error interno del servidor',
            503 => 'Servicio no disponible',
            default => 'Ocurrió un error',
        };
    }

    /**
     * Registra la excepción con los detalles completos
     *
     * Requisito 11.5: Registrar los detalles completos del error para depuración
     *
     * @param \Throwable $e Excepción a registrar
     * @return void
     */
    public static function logException(\Throwable $e): void
    {
        $logDir = __DIR__ . '/../../../storage/logs';

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/error-' . date('Y-m-d') . '.log';

        // Formatear los detalles de la excepción
        $requestId = RequestContext::getRequestId();
        $message = sprintf(
            "[%s]%s %s: %s in %s:%d\nStack trace:\n%s\n\n",
            date('Y-m-d H:i:s'),
            $requestId !== null ? " [{$requestId}]" : '',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            self::formatTraceWithoutArguments($e)
        );

        // Escribir en el archivo de log
        @file_put_contents($logFile, $message, FILE_APPEND);
    }

    /**
     * Formatea la traza de la excepción sin los valores de los argumentos.
     *
     * `Exception::getTraceAsString()` incluye los valores de los argumentos de
     * cada llamada en la pila — si la excepción ocurre mientras una contraseña,
     * token de sesión u otro dato sensible está en el alcance de alguna llamada
     * de la pila (ej. dentro de PasswordHasher::hash($password) o
     * AuthService::authenticate($email, $password, ...)), ese valor en texto
     * plano quedaría escrito permanentemente en el archivo de log. Se construye
     * la traza manualmente usando solo clase/función/archivo/línea, sin argumentos.
     *
     * @param \Throwable $e Excepción
     * @return string Traza de la pila sin argumentos
     */
    private static function formatTraceWithoutArguments(\Throwable $e): string
    {
        $lines = [];

        foreach ($e->getTrace() as $index => $frame) {
            $function = $frame['function'] ?? '';
            $class    = $frame['class'] ?? '';
            $type     = $frame['type'] ?? '';
            $file     = $frame['file'] ?? '[internal function]';
            $line     = $frame['line'] ?? '';

            $location = $file !== '[internal function]' ? "{$file}({$line})" : $file;

            $lines[] = "#{$index} {$location}: {$class}{$type}{$function}()";
        }

        $lines[] = '#' . count($lines) . ' {main}';

        return implode("\n", $lines);
    }

    /**
     * Formatea los errores de validación para la respuesta
     *
     * Requisito 11.4: Incluir los campos con mensajes de error específicos por campo
     *
     * @param array $errors Errores de validación específicos por campo
     * @return Response Respuesta de error de validación
     */
    public static function validationError(array $errors): Response
    {
        return ResponseFormatter::validationError($errors, 400);
    }
}
