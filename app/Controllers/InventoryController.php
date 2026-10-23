<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Models\Inventory\InventoryService;
use App\Models\Inventory\InventoryValidator;
use App\Models\Inventory\ServicePartService;
use App\Core\Http\RequestValidator;
use App\Core\Http\ResponseFormatter;
use App\Core\Http\ErrorHandler;

/**
 * Controlador de Inventario
 *
 * Catálogo de servicios, categorías, productos, proveedores, ingresos de
 * mercancía y repuestos usados en cada servicio.
 */
class InventoryController extends Controller
{
    private InventoryService $inventory;
    private ServicePartService $parts;

    public function __construct()
    {
        $this->inventory = new InventoryService();
        $this->parts = new ServicePartService();
    }

    /**
     * GET /api/service-types (público): catálogo de servicios con precio base.
     */
    public function serviceTypes(Request $request): Response
    {
        try {
            $types = Database::fetchAll(
                'SELECT code, name, description, base_price, estimated_minutes_min, estimated_minutes_max
                 FROM service_types WHERE is_active = TRUE ORDER BY base_price'
            );

            return ResponseFormatter::success(['serviceTypes' => $types], 'Catálogo de servicios', 200);
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    // ── Categorías ──────────────────────────────────────────────────────────

    /** GET /api/inventory/categories */
    public function categories(Request $request): Response
    {
        return $this->respond(fn() => ['categories' => $this->inventory->listCategories()]);
    }

    /** POST /api/inventory/categories */
    public function storeCategory(Request $request): Response
    {
        return $this->withBody($request, InventoryValidator::validateCategory(...), fn(array $data) => [
            'category' => $this->inventory->createCategory($data['name']),
        ], 201);
    }

    // ── Productos ───────────────────────────────────────────────────────────

    /** GET /api/inventory/products */
    public function products(Request $request): Response
    {
        return $this->respond(fn() => ['products' => $this->inventory->listProducts([
            'search'           => $request->query('search'),
            'category_id'      => $request->query('category_id'),
            'availability'     => $request->query('availability'),
            'include_inactive' => $request->query('include_inactive') === 'true',
        ])]);
    }

    /** POST /api/inventory/products */
    public function storeProduct(Request $request): Response
    {
        return $this->withBody($request, InventoryValidator::validateProduct(...), fn(array $data) => [
            'product' => $this->inventory->createProduct($data),
        ], 201);
    }

    /** PUT /api/inventory/products/{id} */
    public function updateProduct(Request $request, int $id): Response
    {
        return $this->withBody(
            $request,
            fn(array $data) => InventoryValidator::validateProduct($data, true),
            fn(array $data) => ['product' => $this->inventory->updateProduct($id, $data)]
        );
    }

    // ── Proveedores ─────────────────────────────────────────────────────────

    /** GET /api/inventory/suppliers */
    public function suppliers(Request $request): Response
    {
        return $this->respond(fn() => ['suppliers' => $this->inventory->listSuppliers($request->query('search'))]);
    }

    /** POST /api/inventory/suppliers */
    public function storeSupplier(Request $request): Response
    {
        return $this->withBody($request, InventoryValidator::validateSupplier(...), fn(array $data) => [
            'supplier' => $this->inventory->createSupplier($data),
        ], 201);
    }

    /** PUT /api/inventory/suppliers/{id} */
    public function updateSupplier(Request $request, int $id): Response
    {
        return $this->withBody(
            $request,
            fn(array $data) => InventoryValidator::validateSupplier($data, true),
            fn(array $data) => ['supplier' => $this->inventory->updateSupplier($id, $data)]
        );
    }

    // ── Ingresos de mercancía ───────────────────────────────────────────────

    /** GET /api/inventory/receipts */
    public function receipts(Request $request): Response
    {
        try {
            $pagination = RequestValidator::parsePagination($request);
            $result = $this->inventory->listReceipts($pagination['perPage'], $pagination['offset']);

            return ResponseFormatter::success([
                'receipts'   => $result['items'],
                'total'      => $result['total'],
                'page'       => $pagination['page'],
                'perPage'    => $pagination['perPage'],
                'totalPages' => (int)ceil($result['total'] / $pagination['perPage']),
            ], 'Ingresos de mercancía obtenidos', 200);
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /** GET /api/inventory/receipts/{id} */
    public function showReceipt(Request $request, int $id): Response
    {
        return $this->respond(fn() => ['receipt' => $this->inventory->getReceipt($id)]);
    }

    /** POST /api/inventory/receipts */
    public function storeReceipt(Request $request): Response
    {
        return $this->withBody($request, InventoryValidator::validateReceipt(...), fn(array $data) => [
            'receipt' => $this->inventory->createReceipt($data, (int)$request->getAttribute('userId')),
        ], 201);
    }

    /** DELETE /api/inventory/receipts/{id} */
    public function destroyReceipt(Request $request, int $id): Response
    {
        return $this->respond(function () use ($id) {
            $this->inventory->deleteReceipt($id);
            return null;
        });
    }

    // ── Repuestos usados en un servicio ─────────────────────────────────────

    /** GET /api/mechanic/requests/{id}/parts */
    public function requestParts(Request $request, int $id): Response
    {
        return $this->respond(function () use ($request, $id) {
            $mechanicId = (int)$request->getAttribute('userId');
            $owner = Database::fetchOne('SELECT mechanic_id FROM service_requests WHERE id = ? AND deleted_at IS NULL', [$id]);
            if ($owner === null || (int)$owner['mechanic_id'] !== $mechanicId) {
                throw new \App\Core\DomainException('Solicitud de servicio no encontrada', 404);
            }
            return ['parts' => $this->parts->listForRequest($id)];
        });
    }

    /** POST /api/mechanic/requests/{id}/parts */
    public function addRequestPart(Request $request, int $id): Response
    {
        return $this->withBody(
            $request,
            function (array $data) {
                $errors = [];
                if (filter_var($data['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    $errors['product_id'] = 'El producto es requerido';
                }
                if (filter_var($data['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    $errors['quantity'] = 'La cantidad debe ser un número entero mayor a 0';
                }
                return $errors;
            },
            fn(array $data) => ['parts' => $this->parts->add(
                $id,
                (int)$request->getAttribute('userId'),
                (int)$data['product_id'],
                (int)$data['quantity']
            )],
            201
        );
    }

    /** DELETE /api/mechanic/requests/{id}/parts/{productId} */
    public function removeRequestPart(Request $request, int $id, int $productId): Response
    {
        return $this->respond(fn() => [
            'parts' => $this->parts->remove($id, (int)$request->getAttribute('userId'), $productId),
        ]);
    }

    // ── Auxiliares ──────────────────────────────────────────────────────────

    private function respond(callable $action, int $status = 200): Response
    {
        try {
            return ResponseFormatter::success($action(), 'OK', $status);
        } catch (\Exception $e) {
            return ErrorHandler::handleException($e);
        }
    }

    /**
     * Valida el body JSON con $validate (que devuelve errores por campo) y,
     * si es válido, ejecuta $action con los datos decodificados.
     */
    private function withBody(Request $request, callable $validate, callable $action, int $status = 200): Response
    {
        $json = RequestValidator::parseJsonBody($request);
        if (!$json['valid']) {
            return ResponseFormatter::error($json['error'], null, $json['statusCode']);
        }

        $data = $json['data'];
        $errors = $validate($data);
        if (!empty($errors)) {
            return ResponseFormatter::validationError($errors);
        }

        return $this->respond(fn() => $action($data), $status);
    }
}
