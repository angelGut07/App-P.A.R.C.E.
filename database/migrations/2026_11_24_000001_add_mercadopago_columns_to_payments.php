<?php

use App\Core\Database;
use App\Core\Migration;

/**
 * Columnas para Mercado Pago (Checkout Pro).
 *
 * - mp_preference_id: link de pago generado (preferencia).
 * - mp_payment_id: pago real en Mercado Pago; UNIQUE para que un mismo pago
 *   no pueda quedar asociado a dos servicios.
 * - mp_status_detail: motivo exacto (p. ej. cc_rejected_insufficient_amount).
 *
 * Es idempotente: si una columna ya existe (porque se creó a mano con ALTER
 * TABLE), la salta en vez de fallar.
 */
class AddMercadopagoColumnsToPayments extends Migration
{
    public function up(): void
    {
        $columns = [
            'mp_preference_id' => 'VARCHAR(100) NULL AFTER pse_bank',
            'mp_payment_id'    => 'VARCHAR(50) NULL AFTER mp_preference_id',
            'mp_status_detail' => 'VARCHAR(100) NULL AFTER mp_payment_id',
        ];

        foreach ($columns as $name => $definition) {
            if (!$this->columnExists('payments', $name)) {
                $this->execute("ALTER TABLE payments ADD COLUMN {$name} {$definition}");
            }
        }

        if (!$this->indexExists('payments', 'uq_payments_mp_payment_id')) {
            $this->execute('ALTER TABLE payments ADD UNIQUE KEY uq_payments_mp_payment_id (mp_payment_id)');
        }
    }

    public function down(): void
    {
        if ($this->indexExists('payments', 'uq_payments_mp_payment_id')) {
            $this->execute('ALTER TABLE payments DROP INDEX uq_payments_mp_payment_id');
        }
        foreach (['mp_status_detail', 'mp_payment_id', 'mp_preference_id'] as $name) {
            if ($this->columnExists('payments', $name)) {
                $this->execute("ALTER TABLE payments DROP COLUMN {$name}");
            }
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return Database::fetchOne(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) !== null;
    }

    private function indexExists(string $table, string $index): bool
    {
        return Database::fetchOne(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        ) !== null;
    }
}
