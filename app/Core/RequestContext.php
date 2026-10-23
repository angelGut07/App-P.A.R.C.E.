<?php

namespace App\Core;

/**
 * Almacena el ID de la petición HTTP actual para que esté disponible en
 * puntos del código que no reciben el objeto Request explícitamente —
 * en particular App::handleException() (el manejador de excepciones no
 * capturadas, registrado vía set_exception_handler, que no tiene acceso al
 * Request) y ErrorHandler::logException() (llamado desde cada controlador
 * sin pasar el Request, para no cambiar esa firma pública).
 *
 * Se establece una única vez por petición en App::run(), antes de
 * despachar al Router.
 */
class RequestContext
{
    private static ?string $requestId = null;

    public static function setRequestId(string $id): void
    {
        self::$requestId = $id;
    }

    public static function getRequestId(): ?string
    {
        return self::$requestId;
    }
}
