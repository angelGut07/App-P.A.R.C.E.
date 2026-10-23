<?php

namespace App\Models\Inventory;

use App\Core\Database;
use App\Core\DatabaseException;
use App\Core\DomainException;
use App\Models\Concerns\CastsColumns;

/**
 * Servicio de Inventario
 *
 * Categorías, productos (repuestos e insumos), proveedores e ingresos de
 * mercancía. El stock de un producto solo cambia aquí (ingresos y ajustes
 * manuales) y en ServicePartService (repuestos usados en un servicio),
 * siempre dentro de una transacción con la fila del producto bloqueada.
 */
class InventoryService
{
    use CastsColumns;

    private const PRODUCT_FLOATS = ['unit_price'];
    private const PRODUCT_BOOLS = ['is_active'];

    /**
     * Disponibilidad derivada del stock (no se guarda en la BD).
     */
    private const AVAILABILITY_SQL = "CASE
            WHEN p.stock_quantity = 0 THEN 'unavailable'
            WHEN p.stock_quantity < p.min_stock THEN 'low_stock'
            ELSE 'available' END";

    // ── Categorías ──────────────────────────────────────────────────────────

    public function listCategories(): array
    {
        return $this->castAll(Database::fetchAll(
            'SELECT c.id, c.name, COUNT(p.id) AS product_count
             FROM product_categories c
             LEFT JOIN products p ON p.category_id = c.id AND p.is_active = TRUE
             GROUP BY c.id, c.name
             ORDER BY c.name'
        ), ints: ['product_count']);
    }

    public function createCategory(string $name): array
    {
        try {
            $id = Database::insert('product_categories', ['name' => trim($name)]);
        } catch (DatabaseException $e) {
            throw $this->duplicateOr($e, 'Ya existe una categoría con ese nombre');
        }

        return Database::fetchOne('SELECT id, name FROM product_categories WHERE id = ?', [$id]);
    }

    // ── Productos ───────────────────────────────────────────────────────────

    /**
     * @param array $filters search, category_id, availability (available|low_stock|unavailable), include_inactive
     */
    public function listProducts(array $filters = []): array
    {
        $sql = 'SELECT p.id, p.name, p.category_id, c.name AS category_name, p.unit, p.unit_price,
                       p.stock_quantity, p.min_stock, p.is_active, p.updated_at,
                       ' . self::AVAILABILITY_SQL . ' AS availability
                FROM products p
                JOIN product_categories c ON c.id = p.category_id
                WHERE 1 = 1';
        $params = [];

        if (empty($filters['include_inactive'])) {
            $sql .= ' AND p.is_active = TRUE';
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (p.name LIKE ? OR c.name LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like);
        }

        if (!empty($filters['category_id'])) {
            $sql .= ' AND p.category_id = ?';
            $params[] = (int)$filters['category_id'];
        }

        if (!empty($filters['availability'])) {
            $sql .= ' AND ' . self::AVAILABILITY_SQL . ' = ?';
            $params[] = $filters['availability'];
        }

        return $this->castAll(Database::fetchAll($sql . ' ORDER BY c.name, p.name', $params), self::PRODUCT_FLOATS, self::PRODUCT_BOOLS);
    }

    public function getProduct(int $productId): array
    {
        $product = Database::fetchOne(
            'SELECT p.id, p.name, p.category_id, c.name AS category_name, p.unit, p.unit_price,
                    p.stock_quantity, p.min_stock, p.is_active, p.updated_at,
                    ' . self::AVAILABILITY_SQL . ' AS availability
             FROM products p
             JOIN product_categories c ON c.id = p.category_id
             WHERE p.id = ?',
            [$productId]
        );

        if ($product === null) {
            throw new DomainException('Producto no encontrado', 404);
        }

        return $this->cast($product, self::PRODUCT_FLOATS, self::PRODUCT_BOOLS);
    }

    public function createProduct(array $data): array
    {
        $this->assertCategoryExists((int)$data['category_id']);

        try {
            $id = Database::insert('products', [
                'category_id'    => (int)$data['category_id'],
                'name'           => trim($data['name']),
                'unit'           => $data['unit'] ?? 'unidad',
                'unit_price'     => (float)($data['unit_price'] ?? 0),
                'stock_quantity' => (int)($data['stock_quantity'] ?? 0),
                'min_stock'      => (int)($data['min_stock'] ?? 0),
            ]);
        } catch (DatabaseException $e) {
            throw $this->duplicateOr($e, 'Ya existe un producto con ese nombre');
        }

        return $this->getProduct($id);
    }

    /**
     * Actualiza datos y disponibilidad de un producto (RF 4.2). Un ajuste
     * manual de stock reemplaza el saldo actual.
     */
    public function updateProduct(int $productId, array $data): array
    {
        $this->getProduct($productId);

        $updates = [];
        foreach (['name', 'unit'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = trim((string)$data[$field]);
            }
        }
        foreach (['category_id', 'stock_quantity', 'min_stock'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = (int)$data[$field];
            }
        }
        if (array_key_exists('unit_price', $data)) {
            $updates['unit_price'] = (float)$data['unit_price'];
        }
        if (array_key_exists('is_active', $data)) {
            $updates['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($updates['category_id'])) {
            $this->assertCategoryExists($updates['category_id']);
        }

        if ($updates !== []) {
            try {
                Database::update('products', $updates, 'id = ?', [$productId]);
            } catch (DatabaseException $e) {
                throw $this->duplicateOr($e, 'Ya existe un producto con ese nombre');
            }
        }

        return $this->getProduct($productId);
    }

    // ── Proveedores ─────────────────────────────────────────────────────────

    public function listSuppliers(?string $search = null): array
    {
        $sql = 'SELECT s.id, s.name, s.tax_id, s.phone, s.email, s.created_at,
                       COUNT(r.id) AS receipt_count, MAX(r.received_on) AS last_receipt_on
                FROM suppliers s
                LEFT JOIN merchandise_receipts r ON r.supplier_id = s.id';
        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= ' WHERE s.name LIKE ? OR s.tax_id LIKE ?';
            $like = '%' . $search . '%';
            array_push($params, $like, $like);
        }

        return $this->castAll(Database::fetchAll($sql . ' GROUP BY s.id ORDER BY s.name', $params), ints: ['receipt_count']);
    }

    public function createSupplier(array $data): array
    {
        try {
            $id = Database::insert('suppliers', $this->supplierFields($data));
        } catch (DatabaseException $e) {
            throw $this->duplicateOr($e, 'Ya existe un proveedor con ese nombre o NIT');
        }

        return Database::fetchOne('SELECT id, name, tax_id, phone, email FROM suppliers WHERE id = ?', [$id]);
    }

    public function updateSupplier(int $supplierId, array $data): array
    {
        if (Database::fetchOne('SELECT id FROM suppliers WHERE id = ?', [$supplierId]) === null) {
            throw new DomainException('Proveedor no encontrado', 404);
        }

        $updates = array_intersect_key($this->supplierFields($data), $data);
        if ($updates !== []) {
            try {
                Database::update('suppliers', $updates, 'id = ?', [$supplierId]);
            } catch (DatabaseException $e) {
                throw $this->duplicateOr($e, 'Ya existe un proveedor con ese nombre o NIT');
            }
        }

        return Database::fetchOne('SELECT id, name, tax_id, phone, email FROM suppliers WHERE id = ?', [$supplierId]);
    }

    // ── Ingresos de mercancía ───────────────────────────────────────────────

    public function listReceipts(int $limit = 50, int $offset = 0): array
    {
        $total = (int)(Database::fetchOne('SELECT COUNT(*) AS total FROM merchandise_receipts')['total'] ?? 0);

        $receipts = Database::fetchAll(
            'SELECT r.id, r.invoice_number, r.received_on, r.created_at,
                    s.id AS supplier_id, s.name AS supplier_name,
                    u.first_name AS registered_by_first_name, u.last_name AS registered_by_last_name,
                    COUNT(i.product_id) AS item_count, COALESCE(SUM(i.quantity), 0) AS total_units
             FROM merchandise_receipts r
             JOIN suppliers s ON s.id = r.supplier_id
             JOIN users u ON u.id = r.registered_by
             LEFT JOIN merchandise_receipt_items i ON i.receipt_id = r.id
             GROUP BY r.id
             ORDER BY r.received_on DESC, r.id DESC
             LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset)
        );

        return ['items' => $this->castAll($receipts, ints: ['item_count', 'total_units']), 'total' => $total];
    }

    public function getReceipt(int $receiptId): array
    {
        $receipt = Database::fetchOne(
            'SELECT r.id, r.invoice_number, r.received_on, r.created_at,
                    s.id AS supplier_id, s.name AS supplier_name, s.tax_id AS supplier_tax_id,
                    u.first_name AS registered_by_first_name, u.last_name AS registered_by_last_name
             FROM merchandise_receipts r
             JOIN suppliers s ON s.id = r.supplier_id
             JOIN users u ON u.id = r.registered_by
             WHERE r.id = ?',
            [$receiptId]
        );

        if ($receipt === null) {
            throw new DomainException('Ingreso de mercancía no encontrado', 404);
        }

        $receipt['items'] = $this->castAll(Database::fetchAll(
            'SELECT i.product_id, p.name AS product_name, c.name AS category_name, p.unit,
                    i.quantity, i.unit_cost
             FROM merchandise_receipt_items i
             JOIN products p ON p.id = i.product_id
             JOIN product_categories c ON c.id = p.category_id
             WHERE i.receipt_id = ?
             ORDER BY p.name',
            [$receiptId]
        ), ['unit_cost']);

        return $receipt;
    }

    /**
     * Registra un ingreso de mercancía y suma las cantidades al stock, todo
     * en una transacción.
     */
    public function createReceipt(array $data, int $adminUserId): array
    {
        Database::beginTransaction();
        try {
            if (Database::fetchOne('SELECT id FROM suppliers WHERE id = ?', [(int)$data['supplier_id']]) === null) {
                throw new DomainException('Proveedor no encontrado', 404);
            }

            try {
                $receiptId = Database::insert('merchandise_receipts', [
                    'supplier_id'    => (int)$data['supplier_id'],
                    'invoice_number' => trim($data['invoice_number']),
                    'received_on'    => $data['received_on'],
                    'registered_by'  => $adminUserId,
                ]);
            } catch (DatabaseException $e) {
                throw $this->duplicateOr($e, 'Esa factura ya fue registrada para este proveedor');
            }

            foreach ($data['items'] as $item) {
                $productId = (int)$item['product_id'];
                $product = Database::fetchOne('SELECT id FROM products WHERE id = ? FOR UPDATE', [$productId]);
                if ($product === null) {
                    throw new DomainException("Producto #{$productId} no encontrado", 404);
                }

                Database::insert('merchandise_receipt_items', [
                    'receipt_id' => $receiptId,
                    'product_id' => $productId,
                    'quantity'   => (int)$item['quantity'],
                    'unit_cost'  => isset($item['unit_cost']) && $item['unit_cost'] !== '' ? (float)$item['unit_cost'] : null,
                ]);

                Database::query(
                    'UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?',
                    [(int)$item['quantity'], $productId]
                );
            }

            Database::commit();
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }

        return $this->getReceipt($receiptId);
    }

    /**
     * Anula un ingreso de mercancía y resta sus cantidades del stock. Falla
     * si parte de esa mercancía ya se usó (el stock quedaría negativo).
     */
    public function deleteReceipt(int $receiptId): void
    {
        Database::beginTransaction();
        try {
            $receipt = Database::fetchOne('SELECT id FROM merchandise_receipts WHERE id = ? FOR UPDATE', [$receiptId]);
            if ($receipt === null) {
                throw new DomainException('Ingreso de mercancía no encontrado', 404);
            }

            $items = Database::fetchAll(
                'SELECT i.product_id, i.quantity, p.stock_quantity, p.name
                 FROM merchandise_receipt_items i
                 JOIN products p ON p.id = i.product_id
                 WHERE i.receipt_id = ?
                 FOR UPDATE',
                [$receiptId]
            );

            foreach ($items as $item) {
                if ((int)$item['stock_quantity'] < (int)$item['quantity']) {
                    throw new DomainException(
                        "No se puede anular: ya se usaron unidades de \"{$item['name']}\" de este ingreso",
                        409
                    );
                }
                Database::query(
                    'UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?',
                    [(int)$item['quantity'], (int)$item['product_id']]
                );
            }

            Database::delete('merchandise_receipts', 'id = ?', [$receiptId]);
            Database::commit();
        } catch (\Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    // ── Auxiliares ──────────────────────────────────────────────────────────

    private function assertCategoryExists(int $categoryId): void
    {
        if (Database::fetchOne('SELECT id FROM product_categories WHERE id = ?', [$categoryId]) === null) {
            throw new DomainException('Categoría no encontrada', 404);
        }
    }

    private function supplierFields(array $data): array
    {
        $clean = fn($key) => isset($data[$key]) && trim((string)$data[$key]) !== '' ? trim((string)$data[$key]) : null;

        return [
            'name'   => $clean('name'),
            'tax_id' => $clean('tax_id'),
            'phone'  => $clean('phone'),
            'email'  => $clean('email'),
        ];
    }

    private function duplicateOr(DatabaseException $e, string $message): \Exception
    {
        return str_contains($e->getMessage(), 'Duplicate entry') ? new DomainException($message, 409) : $e;
    }
}
