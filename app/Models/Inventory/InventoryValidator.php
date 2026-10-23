<?php

namespace App\Models\Inventory;

/**
 * Validaciones del módulo de inventario. Cada método recibe el body ya
 * decodificado y devuelve los errores por campo (vacío si es válido).
 */
class InventoryValidator
{
    public const UNITS = ['unidad', 'juego', 'litro', 'galón', 'kit'];

    public static function validateCategory(array $data): array
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            return ['name' => 'El nombre de la categoría es requerido (máximo 60 caracteres)'];
        }
        return [];
    }

    /**
     * @param bool $partial true para actualizaciones (todos los campos opcionales)
     */
    public static function validateProduct(array $data, bool $partial = false): array
    {
        $errors = [];

        if (!$partial || array_key_exists('name', $data)) {
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 120) {
                $errors['name'] = 'El nombre del producto es requerido (máximo 120 caracteres)';
            }
        }

        if (!$partial || array_key_exists('category_id', $data)) {
            if (filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                $errors['category_id'] = 'La categoría es requerida';
            }
        }

        if (array_key_exists('unit', $data) && !in_array($data['unit'], self::UNITS, true)) {
            $errors['unit'] = 'Unidad inválida. Valores válidos: ' . implode(', ', self::UNITS);
        }

        foreach (['stock_quantity' => 'El stock', 'min_stock' => 'El stock mínimo'] as $field => $label) {
            if (array_key_exists($field, $data)
                && filter_var($data[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                $errors[$field] = "{$label} debe ser un número entero mayor o igual a 0";
            }
        }

        if (array_key_exists('unit_price', $data)
            && (!is_numeric($data['unit_price']) || (float)$data['unit_price'] < 0)) {
            $errors['unit_price'] = 'El precio debe ser un número mayor o igual a 0';
        }

        return $errors;
    }

    public static function validateSupplier(array $data, bool $partial = false): array
    {
        $errors = [];

        if (!$partial || array_key_exists('name', $data)) {
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150) {
                $errors['name'] = 'El nombre del proveedor es requerido (máximo 150 caracteres)';
            }
        }

        if (!empty($data['tax_id']) && !preg_match('/^[0-9-]{5,20}$/', (string)$data['tax_id'])) {
            $errors['tax_id'] = 'El NIT solo puede tener números y guiones (5 a 20 caracteres)';
        }

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'El correo del proveedor no es válido';
        }

        if (!empty($data['phone']) && mb_strlen((string)$data['phone']) > 20) {
            $errors['phone'] = 'El teléfono no debe superar los 20 caracteres';
        }

        return $errors;
    }

    public static function validateReceipt(array $data): array
    {
        $errors = [];

        if (filter_var($data['supplier_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $errors['supplier_id'] = 'El proveedor es requerido';
        }

        $invoice = trim((string)($data['invoice_number'] ?? ''));
        if ($invoice === '' || mb_strlen($invoice) > 50) {
            $errors['invoice_number'] = 'El número de factura o remisión es requerido (máximo 50 caracteres)';
        }

        $date = (string)($data['received_on'] ?? '');
        $parsed = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            $errors['received_on'] = 'La fecha de llegada es requerida (YYYY-MM-DD)';
        } elseif ($date > date('Y-m-d')) {
            $errors['received_on'] = 'La fecha de llegada no puede ser futura';
        }

        $items = $data['items'] ?? null;
        if (!is_array($items) || $items === []) {
            $errors['items'] = 'Agrega al menos un producto';
        } else {
            $seen = [];
            foreach ($items as $i => $item) {
                $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $quantity  = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($productId === false || $quantity === false) {
                    $errors['items'] = "Producto #" . ($i + 1) . ": selecciona el producto y una cantidad mayor a 0";
                    break;
                }
                if (isset($seen[$productId])) {
                    $errors['items'] = 'Un mismo producto no puede aparecer dos veces en el ingreso';
                    break;
                }
                if (isset($item['unit_cost']) && $item['unit_cost'] !== '' && (!is_numeric($item['unit_cost']) || (float)$item['unit_cost'] < 0)) {
                    $errors['items'] = "Producto #" . ($i + 1) . ": el costo unitario debe ser mayor o igual a 0";
                    break;
                }
                $seen[$productId] = true;
            }
        }

        return $errors;
    }
}
