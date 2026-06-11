<?php

namespace App\Support\Simawa;

trait SimawaCollectionHelper
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): bool|null  $filter
     * @return array{total: int, data: list<array<string, mixed>>}
     */
    protected function slicePage(array $rows, SimawaListQuery $query, ?callable $filter = null): array
    {
        if ($filter !== null) {
            $rows = array_values(array_filter($rows, $filter));
        }

        $total = count($rows);
        $data = array_slice($rows, $query->offset, $query->limit);

        return ['total' => $total, 'data' => $data];
    }

    protected function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    protected function nullableFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }

        return null;
    }
}
