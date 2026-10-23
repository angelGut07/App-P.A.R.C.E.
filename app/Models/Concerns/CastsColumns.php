<?php

namespace App\Models\Concerns;

/**
 * MySQL devuelve DECIMAL como texto y BOOLEAN como 0/1. Este helper convierte
 * las columnas indicadas para que el frontend reciba números y booleanos.
 */
trait CastsColumns
{
    /**
     * @param array    $row    Fila a convertir (null se devuelve tal cual)
     * @param string[] $floats Columnas DECIMAL
     * @param string[] $bools  Columnas BOOLEAN
     * @param string[] $ints   Columnas enteras calculadas (COUNT, SUM)
     */
    protected function cast(?array $row, array $floats = [], array $bools = [], array $ints = []): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach ($floats as $c) {
            if (isset($row[$c])) $row[$c] = (float)$row[$c];
        }
        foreach ($bools as $c) {
            if (isset($row[$c])) $row[$c] = (bool)$row[$c];
        }
        foreach ($ints as $c) {
            if (isset($row[$c])) $row[$c] = (int)$row[$c];
        }
        return $row;
    }

    protected function castAll(array $rows, array $floats = [], array $bools = [], array $ints = []): array
    {
        return array_map(fn($row) => $this->cast($row, $floats, $bools, $ints), $rows);
    }
}
