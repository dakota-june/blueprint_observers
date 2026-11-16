<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Support;

/**
 * Finds what must be unique on a Blueprint model:
 *
 *  - every column written with the `unique` modifier            (`number: string unique`)
 *  - every entry of type `unique` under the model's `indexes:`  (`- unique: tenant_id, slug`)
 *
 * Each constraint is the list of column names it covers, so a composite unique index is one constraint.
 * Duplicates (a column that is also in a one-column unique index) are reported once.
 */
final class UniqueConstraints
{
    /**
     * @param  object  $model  A Blueprint\Models\Model (anything with columns() and, optionally, indexes()).
     * @return array<int, array<int, string>>
     */
    public static function for(object $model): array
    {
        $constraints = [];

        foreach ($model->columns() as $column) {
            if (in_array('unique', $column->modifiers(), true)) {
                $constraints[] = [$column->name()];
            }
        }

        if (method_exists($model, 'indexes')) {
            foreach ($model->indexes() as $index) {
                $columns = array_values(array_filter(array_map('strval', $index->columns()), static fn (string $c): bool => $c !== ''));

                if ($index->type() === 'unique' && $columns !== []) {
                    $constraints[] = $columns;
                }
            }
        }

        $unique = [];
        foreach ($constraints as $columns) {
            $unique[implode(',', $columns)] = $columns;
        }

        return array_values($unique);
    }
}
