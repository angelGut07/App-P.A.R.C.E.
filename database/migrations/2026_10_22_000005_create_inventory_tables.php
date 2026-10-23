<?php

use App\Core\Migration;

/**
 * Inventario: categorías, productos (repuestos/insumos), proveedores,
 * ingresos de mercancía y repuestos usados en cada servicio.
 *
 * Normalización (3FN):
 * - Categoría y proveedor son entidades propias; productos e ingresos solo
 *   guardan su FK (antes eran texto libre repetido en cada fila).
 * - Un ingreso de mercancía (cabecera: proveedor, factura, fecha) y sus
 *   líneas (producto, cantidad, costo) están separados: los datos de la
 *   factura no se repiten por cada producto.
 * - El estado "disponible / stock bajo / agotado" NO se guarda: se deriva de
 *   `stock_quantity` frente a `min_stock`.
 * - `stock_quantity` es el saldo actual del producto. Solo lo modifican, dentro
 *   de la misma transacción, InventoryService (ingresos de mercancía y ajustes
 *   manuales) y el registro de repuestos usados en un servicio.
 * - `unit_price` en service_request_parts es el precio cobrado en ese servicio
 *   (precio histórico), que no tiene por qué coincidir con el precio actual
 *   del producto.
 */
class CreateInventoryTables extends Migration
{
    public function up(): void
    {
        $this->execute("
            CREATE TABLE product_categories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(60) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE products (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                category_id INT UNSIGNED NOT NULL,
                name VARCHAR(120) NOT NULL UNIQUE,
                unit ENUM('unidad', 'juego', 'litro', 'galón', 'kit') NOT NULL DEFAULT 'unidad',
                unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
                stock_quantity INT UNSIGNED NOT NULL DEFAULT 0,
                min_stock INT UNSIGNED NOT NULL DEFAULT 0,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_products_category (category_id),
                CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES product_categories(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE suppliers (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL UNIQUE,
                tax_id VARCHAR(20) NULL UNIQUE COMMENT 'NIT',
                phone VARCHAR(20) NULL,
                email VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE merchandise_receipts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                supplier_id BIGINT UNSIGNED NOT NULL,
                invoice_number VARCHAR(50) NOT NULL,
                received_on DATE NOT NULL,
                registered_by BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

                UNIQUE KEY uq_merchandise_receipts_invoice (supplier_id, invoice_number),
                INDEX idx_merchandise_receipts_received_on (received_on),
                CONSTRAINT fk_merchandise_receipts_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
                CONSTRAINT fk_merchandise_receipts_user FOREIGN KEY (registered_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE merchandise_receipt_items (
                receipt_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                quantity INT UNSIGNED NOT NULL,
                unit_cost DECIMAL(10,2) NULL,

                PRIMARY KEY (receipt_id, product_id),
                CONSTRAINT fk_receipt_items_receipt FOREIGN KEY (receipt_id) REFERENCES merchandise_receipts(id) ON DELETE CASCADE,
                CONSTRAINT fk_receipt_items_product FOREIGN KEY (product_id) REFERENCES products(id),
                CONSTRAINT chk_receipt_items_quantity CHECK (quantity > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->execute("
            CREATE TABLE service_request_parts (
                service_request_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                quantity INT UNSIGNED NOT NULL,
                unit_price DECIMAL(10,2) NOT NULL,
                added_by BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (service_request_id, product_id),
                CONSTRAINT fk_request_parts_request FOREIGN KEY (service_request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
                CONSTRAINT fk_request_parts_product FOREIGN KEY (product_id) REFERENCES products(id),
                CONSTRAINT fk_request_parts_user FOREIGN KEY (added_by) REFERENCES users(id),
                CONSTRAINT chk_request_parts_quantity CHECK (quantity > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('service_request_parts');
        $this->dropTable('merchandise_receipt_items');
        $this->dropTable('merchandise_receipts');
        $this->dropTable('suppliers');
        $this->dropTable('products');
        $this->dropTable('product_categories');
    }
}
