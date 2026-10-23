<?php

namespace App\Models\Inventory;

use App\Core\Database;
use App\Core\DomainException;
use App\Models\Concerns\CastsColumns;

/**
 * Repuestos e insumos usados en una solicitud de servicio (RF 4.2 / 4.3).
 *
 * El mecánico asignado los registra mientras el servicio está asignado o en
 * progreso; cada registro descuenta el stock del producto en la misma
 * transacción. Una vez existe un pago para el servicio, los repuestos ya no
 * se pueden modificar (el monto cobrado depende de ellos).
 */
class ServicePartService
{
    use CastsColumns;

    private const EDITABLE_STATUSES = ['assigned', 'in_progress'];

    /**
     * Lista los repuestos de una solicitud con su subtotal.
     */
    public function listForRequest(int $requestId): array
    {
        return $this->castAll(Database::fetchAll(
            'SELECT sp.product_id, p.name AS product_name, p.unit, sp.quantity, sp.unit_price,
                    (sp.quantity * sp.unit_price) AS subtotal, sp.created_at
             FROM service_request_parts sp
             JOIN products p ON p.id = sp.product_id
             WHERE sp.service_request_id = ?
             ORDER BY sp.created_at',
            [$requestId]
        ), ['unit_price', 'subtotal']);
    }

    /**
     * Agrega (o suma cantidad a) un repuesto usado en el servicio y lo
     * descuenta del inventario.
     *
     * @throws DomainException 404/403/409 según la regla que no se cumpla
     */
    public function add(int $requestId, int $mechanicId, int $productId, int $quantity): array
    {
        if ($quantity < 1) {
            throw new DomainException('La cantidad debe ser mayor a 0', 400);
        }

        Database::beginTransaction();
        try {
            $this->lockEditableRequest($requestId, $mechanicId);

            $product = Database::fetchOne(
                'SELECT id, name, unit_price, stock_quantity, is_active FROM products WHERE id = ? FOR UPDATE',
                [$productId]
            );
            if ($product === null || !$product['is_active']) {
                throw new DomainException('Producto no encontrado', 404);
            }
            if ((int)$product['stock_quantity'] < $quantity) {
                throw new DomainException(
                    "Stock insuficiente de \"{$product['name']}\" (disponible: {$product['stock_quantity']})",
                    409
                );
            }

            Database::query(
                'INSERT INTO service_request_parts (service_request_id, product_id, quantity, unit_price, added_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)',
                [$requestId, $productId, $quantity, $product['unit_price'], $mechanicId]
            );
            Database::query(
                'UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?',
                [$quantity, $productId]
            );

            Database::commit();
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }

        return $this->listForRequest($requestId);
    }

    /**
     * Quita un repuesto del servicio y devuelve su cantidad al inventario.
     */
    public function remove(int $requestId, int $mechanicId, int $productId): array
    {
        Database::beginTransaction();
        try {
            $this->lockEditableRequest($requestId, $mechanicId);

            $part = Database::fetchOne(
                'SELECT quantity FROM service_request_parts WHERE service_request_id = ? AND product_id = ? FOR UPDATE',
                [$requestId, $productId]
            );
            if ($part === null) {
                throw new DomainException('Ese repuesto no está registrado en el servicio', 404);
            }

            Database::delete('service_request_parts', 'service_request_id = ? AND product_id = ?', [$requestId, $productId]);
            Database::query(
                'UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?',
                [(int)$part['quantity'], $productId]
            );

            Database::commit();
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }

        return $this->listForRequest($requestId);
    }

    private function lockEditableRequest(int $requestId, int $mechanicId): void
    {
        $request = Database::fetchOne(
            'SELECT id, mechanic_id, status FROM service_requests WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
            [$requestId]
        );

        if ($request === null) {
            throw new DomainException('Solicitud de servicio no encontrada', 404);
        }
        if ((int)$request['mechanic_id'] !== $mechanicId) {
            throw new DomainException('No estás asignado a esta solicitud de servicio', 403);
        }
        if (!in_array($request['status'], self::EDITABLE_STATUSES, true)) {
            throw new DomainException('Solo se registran repuestos en servicios asignados o en progreso', 409);
        }
        if (Database::fetchOne('SELECT id FROM payments WHERE service_request_id = ?', [$requestId]) !== null) {
            throw new DomainException('El servicio ya tiene un pago registrado', 409);
        }
    }
}
