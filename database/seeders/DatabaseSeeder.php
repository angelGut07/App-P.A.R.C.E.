<?php

use App\Core\Database;
use App\Core\Seeder;
use App\Models\Auth\PasswordHasher;

/**
 * Datos de demostración
 *
 * Crea una cuenta por rol, un mecánico aprobado, un vehículo con documentos,
 * inventario con proveedores y un ingreso de mercancía, y un servicio
 * completado con repuestos, calificaciones, encuesta y pago. Es idempotente:
 * si la cuenta del administrador de demo ya existe, no hace nada.
 *
 * Contraseña de todas las cuentas de demo: Parce2026*  (solo para desarrollo
 * y pruebas; no ejecutar en producción).
 */
class DatabaseSeeder extends Seeder
{
    private const PASSWORD = 'Parce2026*';

    public function run(): void
    {
        if ($this->exists('users', 'email = ?', ['admin@parce.test'])) {
            $this->log('Los datos de demostración ya existen; no se hace nada.');
            return;
        }

        $this->beginTransaction();
        try {
            $users = $this->seedUsers();
            $vehicleId = $this->seedVehicle($users['customer']);
            $products = $this->seedInventory($users['admin']);
            $this->seedServices($users, $vehicleId, $products);
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }

        $this->log('Datos de demostración creados. Contraseña de las cuentas: ' . self::PASSWORD);
    }

    /** @return array{admin:int, customer:int, mechanic:int} */
    private function seedUsers(): array
    {
        $hash = (new PasswordHasher())->hash(self::PASSWORD);
        $roles = array_column(Database::fetchAll('SELECT id, slug FROM roles'), 'id', 'slug');

        $create = function (string $email, string $first, string $last, string $idNumber, array $roleSlugs, array $extra = []) use ($hash, $roles): int {
            $id = $this->insert('users', array_merge([
                'email'             => $email,
                'password_hash'     => $hash,
                'first_name'        => $first,
                'last_name'         => $last,
                'id_number'         => $idNumber,
                'phone'             => '300' . substr($idNumber, -7),
                'account_status'    => 'active',
                'email_verified_at' => date('Y-m-d H:i:s'),
            ], $extra));
            foreach ($roleSlugs as $slug) {
                $this->insert('user_roles', ['user_id' => $id, 'role_id' => $roles[$slug]]);
            }
            return $id;
        };

        $admin = $create('admin@parce.test', 'Laura', 'Administradora', '1000000001', ['administrator']);
        $customer = $create('cliente@parce.test', 'Carlos', 'Rodríguez', '1000000002', ['customer'], [
            'driver_license_number'          => 'LIC-2024-0002',
            'driver_license_expiration_date' => date('Y-m-d', strtotime('+2 years')),
            'driver_license_uploaded_at'     => date('Y-m-d H:i:s'),
        ]);
        $mechanic = $create('mecanico@parce.test', 'Roberto', 'Silva', '1000000003', ['customer', 'mechanic'], [
            'driver_license_number'          => 'LIC-2024-0003',
            'driver_license_expiration_date' => date('Y-m-d', strtotime('+3 years')),
            'driver_license_uploaded_at'     => date('Y-m-d H:i:s'),
        ]);

        $this->insert('mechanic_applications', [
            'user_id'             => $mechanic,
            'specialty'           => 'Mecánica General',
            'years_experience'    => 8,
            'certification_title' => 'Técnico en Mecánica Automotriz (SENA)',
            'status'              => 'approved',
            'reviewed_by'         => $admin,
            'reviewed_at'         => date('Y-m-d H:i:s'),
            'review_notes'        => 'Documentación verificada',
        ]);

        return ['admin' => $admin, 'customer' => $customer, 'mechanic' => $mechanic];
    }

    private function seedVehicle(int $customerId): int
    {
        $vehicleId = $this->insert('vehicles', [
            'user_id'       => $customerId,
            'license_plate' => 'ABC123',
            'make'          => 'Chevrolet',
            'model'         => 'Spark GT',
            'year'          => 2020,
            'color'         => 'Rojo',
            'vehicle_type'  => 'sedan',
            'fuel_type'     => 'gasoline',
            'is_primary'    => true,
        ]);

        foreach (['soat' => '+8 months', 'tecnomecanica' => '+20 days'] as $type => $expires) {
            $this->insert('vehicle_documents', [
                'vehicle_id'      => $vehicleId,
                'document_type'   => $type,
                'document_number' => strtoupper($type) . '-2026-' . $vehicleId,
                'expires_at'      => date('Y-m-d', strtotime($expires)),
            ]);
        }

        return $vehicleId;
    }

    /** @return array<string,int> nombre del producto => id */
    private function seedInventory(int $adminId): array
    {
        $catalog = [
            'Neumáticos'  => [['Llanta 195/65 R15', 'unidad', 180000, 12, 4]],
            'Filtros'     => [['Filtro de aceite', 'unidad', 25000, 3, 5], ['Filtro de aire', 'unidad', 30000, 9, 3]],
            'Lubricantes' => [['Aceite 5W-30 (1L)', 'litro', 18000, 24, 10], ['Líquido refrigerante (1L)', 'litro', 15000, 6, 4]],
            'Eléctrico'   => [['Batería 12V 60Ah', 'unidad', 320000, 0, 2]],
            'Frenos'      => [['Pastillas de freno delanteras', 'juego', 85000, 8, 4], ['Líquido de frenos DOT4', 'litro', 22000, 5, 3]],
            'Combustible' => [['Gasolina corriente', 'galón', 16000, 2, 5]],
            'Motor'       => [['Correa de distribución', 'unidad', 95000, 5, 2], ['Bujías NGK', 'juego', 60000, 7, 2]],
        ];

        $products = [];
        foreach ($catalog as $category => $items) {
            $categoryId = $this->insert('product_categories', ['name' => $category]);
            foreach ($items as [$name, $unit, $price, $stock, $min]) {
                $products[$name] = $this->insert('products', [
                    'category_id'    => $categoryId,
                    'name'           => $name,
                    'unit'           => $unit,
                    'unit_price'     => $price,
                    'stock_quantity' => $stock,
                    'min_stock'      => $min,
                ]);
            }
        }

        $supplierId = $this->insert('suppliers', [
            'name'   => 'Distribuidora AutoParts Ltda',
            'tax_id' => '900123456-7',
            'phone'  => '6017451200',
            'email'  => 'ventas@autoparts.test',
        ]);
        $this->insert('suppliers', [
            'name'   => 'Eléctricos del Norte S.A.S',
            'tax_id' => '901765432-1',
            'phone'  => '6016234455',
        ]);

        // El stock sembrado arriba ya incluye este ingreso.
        $receiptId = $this->insert('merchandise_receipts', [
            'supplier_id'    => $supplierId,
            'invoice_number' => 'FAC-2026-0441',
            'received_on'    => date('Y-m-d', strtotime('-10 days')),
            'registered_by'  => $adminId,
        ]);
        foreach (['Llanta 195/65 R15' => [8, 150000], 'Aceite 5W-30 (1L)' => [20, 14000]] as $name => [$qty, $cost]) {
            $this->insert('merchandise_receipt_items', [
                'receipt_id' => $receiptId,
                'product_id' => $products[$name],
                'quantity'   => $qty,
                'unit_cost'  => $cost,
            ]);
        }

        return $products;
    }

    private function seedServices(array $users, int $vehicleId, array $products): void
    {
        $completedAt = date('Y-m-d H:i:s', strtotime('-2 days'));
        $requestId = $this->insert('service_requests', [
            'service_code'   => 'SR-PENDIENTE',
            'customer_id'    => $users['customer'],
            'vehicle_id'     => $vehicleId,
            'mechanic_id'    => $users['mechanic'],
            'emergency_type' => 'tire',
            'description'    => 'Llanta delantera pinchada en la Autopista Norte',
            'priority'       => 'urgent',
            'latitude'       => 4.71100000,
            'longitude'      => -74.07210000,
            'status'         => 'completed',
            'requested_at'   => date('Y-m-d H:i:s', strtotime('-2 days -1 hour')),
            'assigned_at'    => date('Y-m-d H:i:s', strtotime('-2 days -50 minutes')),
            'started_at'     => date('Y-m-d H:i:s', strtotime('-2 days -30 minutes')),
            'completed_at'   => $completedAt,
            'estimated_cost' => 50000,
            'final_cost'     => 50000,
        ]);
        $this->update('service_requests', ['service_code' => sprintf('SR-%s-%06d', date('Y'), $requestId)], 'id = ?', [$requestId]);

        $this->insert('service_request_parts', [
            'service_request_id' => $requestId,
            'product_id'         => $products['Llanta 195/65 R15'],
            'quantity'           => 1,
            'unit_price'         => 180000,
            'added_by'           => $users['mechanic'],
        ]);

        $this->insert('ratings', [
            'service_request_id' => $requestId,
            'rating_type'        => 'customer_to_mechanic',
            'rater_id'           => $users['customer'],
            'ratee_id'           => $users['mechanic'],
            'score'              => 5,
            'punctuality_score'  => 5,
            'quality_score'      => 4,
            'comment'            => 'Llegó rápido y fue muy amable.',
        ]);
        $this->insert('ratings', [
            'service_request_id' => $requestId,
            'rating_type'        => 'mechanic_to_customer',
            'rater_id'           => $users['mechanic'],
            'ratee_id'           => $users['customer'],
            'score'              => 5,
            'comment'            => 'Cliente puntual y claro con la ubicación.',
        ]);

        $this->insert('surveys', [
            'service_request_id'   => $requestId,
            'overall_satisfaction' => 5,
            'would_recommend'      => true,
            'comments'             => 'Excelente servicio.',
        ]);

        $paymentId = $this->insert('payments', [
            'service_request_id' => $requestId,
            'receipt_number'     => 'REC-PENDIENTE',
            'method'             => 'cash',
            'status'             => 'confirmed',
            'amount'             => 230000,
            'reviewed_by'        => $users['mechanic'],
            'reviewed_at'        => $completedAt,
        ]);
        $this->update('payments', ['receipt_number' => sprintf('REC-%s-%06d', date('Y'), $paymentId)], 'id = ?', [$paymentId]);

        $this->insert('pqr', [
            'user_id'     => $users['customer'],
            'ticket_code' => 'PQR-' . date('Y') . '-000001',
            'type'        => 'sugerencia',
            'subject'     => 'Notificaciones por correo',
            'description' => 'Sería útil recibir un correo cuando el mecánico acepta la solicitud.',
        ]);
    }
}
