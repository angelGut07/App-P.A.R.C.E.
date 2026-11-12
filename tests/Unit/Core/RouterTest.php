<?php

namespace Tests\Unit\Core;

use App\Core\Http\ResponseFormatter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\SimulatesHttp;

/**
 * Despacho de rutas: los parámetros de la URL se convierten al tipo que
 * declara la acción, y un valor que no puede ser un id responde 404 en vez
 * de llegar a la acción como texto (antes: TypeError y 500).
 */
class RouterTest extends TestCase
{
    use SimulatesHttp;

    protected function setUp(): void
    {
        $this->backupSuperglobals();
        RouterTestController::$instances = 0;
        RouterTestController::$received = null;
    }

    protected function tearDown(): void
    {
        $this->restoreSuperglobals();
    }

    private function dispatch(Router $router, string $method, string $uri): Response
    {
        $_SERVER['REQUEST_URI'] = $uri;

        return $router->dispatch($this->makeRequest($method));
    }

    public function testNumericIdReachesTheClosureAsInt(): void
    {
        $received = null;
        $router = new Router();
        $router->get('/api/items/{id}', function (Request $request, int $id) use (&$received) {
            $received = $id;
            return ResponseFormatter::success(['id' => $id]);
        });

        $response = $this->dispatch($router, 'GET', '/api/items/42');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(42, $received);
    }

    public function testZeroIsAValidIdForTheRouter(): void
    {
        $router = new Router();
        $router->get('/api/items/{id}', [RouterTestController::class, 'show']);

        $response = $this->dispatch($router, 'GET', '/api/items/0');

        // El router lo deja pasar; decidir si existe es trabajo del controlador
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([0], RouterTestController::$received);
    }

    public static function invalidIds(): array
    {
        return [
            'texto'                => ['abc'],
            'dígitos y letras'     => ['12abc'],
            'negativo'             => ['-1'],
            'con signo +'          => ['+1'],
            'decimal'              => ['1.5'],
            'ceros a la izquierda' => ['012'],
            'no cabe en un int'    => ['99999999999999999999'],
            'codificado en la URL' => ['%31'],
        ];
    }

    #[DataProvider('invalidIds')]
    public function testInvalidIdAnswers404WithoutCreatingTheController(string $id): void
    {
        $router = new Router();
        $router->get('/api/items/{id}', [RouterTestController::class, 'show']);

        $response = $this->dispatch($router, 'GET', '/api/items/' . $id);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['success' => false, 'error' => 'Recurso no encontrado'], $this->responseBody($response));
        $this->assertSame(0, RouterTestController::$instances, 'Un id inválido no debe instanciar el controlador');
    }

    public function testInvalidIdAnswers404ForClosureActions(): void
    {
        $executed = false;
        $router = new Router();
        $router->get('/api/items/{id}', function (Request $request, int $id) use (&$executed) {
            $executed = true;
            return ResponseFormatter::success();
        });

        $response = $this->dispatch($router, 'GET', '/api/items/abc');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($executed);
    }

    public function testStringParamsArriveUnchanged(): void
    {
        $router = new Router();
        $router->post('/api/items/{id}/services/{code}/document', [RouterTestController::class, 'document']);

        $response = $this->dispatch($router, 'POST', '/api/items/7/services/battery/document');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([7, 'battery'], RouterTestController::$received);
    }

    public function testEveryIntParamIsValidated(): void
    {
        $router = new Router();
        $router->delete('/api/items/{id}/parts/{productId}', [RouterTestController::class, 'removePart']);

        $valid = $this->dispatch($router, 'DELETE', '/api/items/7/parts/3');
        $this->assertSame(200, $valid->getStatusCode());
        $this->assertSame([7, 3], RouterTestController::$received);

        $invalid = $this->dispatch($router, 'DELETE', '/api/items/7/parts/abc');
        $this->assertSame(404, $invalid->getStatusCode());
    }

    public function testUnknownRouteAnswers404WithTheApiErrorFormat(): void
    {
        $response = $this->dispatch(new Router(), 'GET', '/api/no-existe');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['success' => false, 'error' => 'Ruta no encontrada'], $this->responseBody($response));
    }
}

/**
 * Controlador de prueba: cuenta cuántas veces se instancia y guarda los
 * parámetros que recibe, para comprobar qué le llega desde el router.
 */
final class RouterTestController
{
    public static int $instances = 0;
    public static ?array $received = null;

    public function __construct()
    {
        self::$instances++;
    }

    public function show(Request $request, int $id): Response
    {
        self::$received = [$id];
        return ResponseFormatter::success();
    }

    public function document(Request $request, int $id, string $code): Response
    {
        self::$received = [$id, $code];
        return ResponseFormatter::success();
    }

    public function removePart(Request $request, int $id, int $productId): Response
    {
        self::$received = [$id, $productId];
        return ResponseFormatter::success();
    }
}
