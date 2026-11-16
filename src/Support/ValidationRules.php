<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Support;

use Dakotajune\BlueprintObservers\Models\ValidationSpec;

/**
 * Derives Laravel validation rules from a Blueprint model's columns, and applies an observer's overrides.
 *
 * Rules are returned as PHP expressions (`'required'`, `Rule::in(['a', 'b'])`) ready to be written into the
 * generated `validationRules()` method.
 *
 *   nullable column                 -> nullable
 *   otherwise, no default           -> required   (a default means the database fills it in)
 *   string / char                   -> string, max:length (default 255)
 *   text types                      -> string, max:bytes (not for longText)
 *   integer types                   -> integer, min/max of the column type (unsigned: min:0)
 *   id / foreignId (not the key)    -> integer, min:1
 *   decimal:p,s                     -> numeric, decimal:0,s, min/max that fit the column
 *   float / double                  -> numeric
 *   boolean                         -> boolean
 *   date / dateTime / timestamp     -> date
 *   uuid / ulid / ipAddress / mac   -> uuid / ulid / ip / mac_address
 *   enum                            -> Rule::in([...])
 *
 * Not derived: the primary key, auto increments, created_at / updated_at / deleted_at, and column types with no
 * useful rule (json, binary, time, geometry ...), which still get required / nullable. Uniqueness is the job of
 * the `unique` snippet, not of validation.
 */
final class ValidationRules
{
    private const SKIPPED_NAMES = ['created_at', 'updated_at', 'deleted_at'];

    private const INCREMENTS = ['increments', 'bigincrements', 'mediumincrements', 'smallincrements', 'tinyincrements'];

    private const TEXT_MAX = ['tinytext' => 255, 'text' => 65535, 'mediumtext' => 16777215];

    /** type => [signed min, signed max, unsigned max] */
    private const INTEGERS = [
        'tinyinteger' => [-128, 127, 255],
        'smallinteger' => [-32768, 32767, 65535],
        'mediuminteger' => [-8388608, 8388607, 16777215],
        'integer' => [-2147483648, 2147483647, 4294967295],
    ];

    /**
     * @param  object  $model  A Blueprint\Models\Model
     * @return array<string, array<int, string>>  column => rule expressions
     */
    public function derive(object $model): array
    {
        $rules = [];

        foreach ($model->columns() as $column) {
            if ($this->skipped($model, $column)) {
                continue;
            }

            $rules[$column->name()] = $this->forColumn($column);
        }

        return $rules;
    }

    /**
     * @param  array<string, array<int, string>>  $derived
     * @return array<string, array<int, string>>  column => rule expressions, overrides applied
     */
    public function apply(array $derived, ?ValidationSpec $spec): array
    {
        $rules = $derived;

        foreach ($spec->overrides ?? [] as $column => $override) {
            if ($override === false) {
                unset($rules[$column]);

                continue;
            }

            $expanded = [];

            foreach ($override as $rule) {
                if ($rule === ValidationSpec::DERIVED) {
                    array_push($expanded, ...($derived[$column] ?? []));
                } else {
                    $expanded[] = var_export($rule, true);
                }
            }

            $rules[$column] = array_values(array_unique($expanded));
        }

        return $rules;
    }

    private function skipped(object $model, object $column): bool
    {
        $type = strtolower($column->dataType());

        return in_array($column->name(), self::SKIPPED_NAMES, true)
            || in_array($type, self::INCREMENTS, true)
            || $column->name() === $model->primaryKey() && $this->isKeyType($type)
            || str_contains($type, 'morphs');
    }

    private function isKeyType(string $type): bool
    {
        return in_array($type, ['id', 'uuid', 'ulid', 'integer', 'biginteger', 'unsignedbiginteger', 'unsignedinteger'], true);
    }

    /** @return array<int, string> */
    private function forColumn(object $column): array
    {
        $rules = [];

        if ($column->isNullable()) {
            $rules[] = "'nullable'";
        } elseif ($column->defaultValue() === null) {
            $rules[] = "'required'";
        }

        return [...$rules, ...$this->typeRules($column)];
    }

    /** @return array<int, string> */
    private function typeRules(object $column): array
    {
        $type = strtolower($column->dataType());
        $attributes = $column->attributes();
        $unsigned = $column->isUnsigned() || str_starts_with($type, 'unsigned');
        $type = str_starts_with($type, 'unsigned') ? substr($type, 8) : $type;

        if (isset(self::INTEGERS[$type])) {
            [$min, $max, $unsignedMax] = self::INTEGERS[$type];

            return $unsigned
                ? ["'integer'", "'min:0'", "'max:{$unsignedMax}'"]
                : ["'integer'", "'min:{$min}'", "'max:{$max}'"];
        }

        return match (true) {
            in_array($type, ['string', 'char'], true) => ["'string'", "'max:" . $this->length($attributes) . "'"],
            isset(self::TEXT_MAX[$type]) => ["'string'", "'max:" . self::TEXT_MAX[$type] . "'"],
            $type === 'longtext' => ["'string'"],
            $type === 'biginteger' => $unsigned ? ["'integer'", "'min:0'"] : ["'integer'"],
            in_array($type, ['id', 'foreignid'], true) => ["'integer'", "'min:1'"],
            in_array($type, ['uuid', 'foreignuuid'], true) => ["'uuid'"],
            in_array($type, ['ulid', 'foreignulid'], true) => ["'ulid'"],
            $type === 'boolean' => ["'boolean'"],
            in_array($type, ['date', 'datetime', 'datetimetz', 'timestamp', 'timestamptz'], true) => ["'date'"],
            $type === 'year' => ["'integer'"],
            in_array($type, ['float', 'double'], true) => ["'numeric'"],
            $type === 'decimal' => $this->decimal($attributes, $unsigned),
            $type === 'enum' => $this->enum($attributes),
            $type === 'ipaddress' => ["'ip'"],
            $type === 'macaddress' => ["'mac_address'"],
            default => [],
        };
    }

    private function length(array $attributes): int
    {
        return isset($attributes[0]) && ctype_digit((string) $attributes[0]) && (int) $attributes[0] > 0 ? (int) $attributes[0] : 255;
    }

    /** @return array<int, string> */
    private function decimal(array $attributes, bool $unsigned): array
    {
        $precision = isset($attributes[0]) && ctype_digit((string) $attributes[0]) ? (int) $attributes[0] : 8;
        $scale = isset($attributes[1]) && ctype_digit((string) $attributes[1]) ? (int) $attributes[1] : 2;
        $scale = min($scale, $precision);

        $integerDigits = $precision - $scale;
        $max = ($integerDigits > 0 ? str_repeat('9', $integerDigits) : '0') . ($scale > 0 ? '.' . str_repeat('9', $scale) : '');

        return ["'numeric'", "'decimal:0,{$scale}'", $unsigned ? "'min:0'" : "'min:-{$max}'", "'max:{$max}'"];
    }

    /** @return array<int, string> */
    private function enum(array $attributes): array
    {
        $values = array_values(array_filter(array_map('strval', $attributes), static fn (string $v): bool => $v !== ''));

        if ($values === []) {
            return [];
        }

        return ['Rule::in([' . implode(', ', array_map(static fn (string $v): string => var_export($v, true), $values)) . '])'];
    }
}
