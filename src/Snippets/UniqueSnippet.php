<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Snippets;

use Illuminate\Support\Str;

/**
 * Built-in snippet `unique`: checks, before a model is written, that its unique columns and unique indexes
 * are not already taken, and throws a ValidationException ("The number has already been taken.") if they are.
 *
 *   observers:
 *     Invoice:
 *       events:
 *         creating: [unique]
 *         updating: [unique]
 *
 * Every constraint gets its own method (`ensureUniqueNumber`, `ensureUniqueTenantIdAndSlug`, ...) that the
 * event method calls, so the checks can be read, overridden and taken over one by one.
 *
 * The database index stays the real guard: this check cannot close the race between two concurrent writes,
 * and it is skipped by anything that bypasses model events (query builder inserts, upsert(), saveQuietly()).
 * It gives a readable validation error instead of a database exception. Soft deleted rows count as taken,
 * because the database index counts them.
 */
final class UniqueSnippet implements Snippet
{
    public const NAME = 'unique';

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(string $event): bool
    {
        return in_array($event, ['creating', 'updating'], true);
    }

    public function render(SnippetContext $context): SnippetOutput
    {
        $constraints = $context->uniqueConstraints();

        if ($constraints === []) {
            throw new SnippetException(
                "Snippet [unique] found no unique column or unique index on [{$context->model}] ({$context->observer}). "
                . 'Define the model under models: and mark a column `unique` (or add `- unique: a, b` under indexes:).',
            );
        }

        $calls = [];
        $methods = [];

        foreach ($constraints as $columns) {
            $name = $this->methodName($columns);
            $label = implode(' / ', array_map(static fn (string $c): string => str_replace('_', ' ', $c), $columns));

            $calls[] = "\$this->{$name}({$context->variableName()});";
            $methods[] = new SnippetMethod(
                $name,
                $this->body($context->variableName(), $columns, $label),
                "Make sure the {$label} of the {$context->model} stays unique.",
            );
        }

        return new SnippetOutput(
            implode("\n", $calls),
            ['Illuminate\\Validation\\ValidationException'],
            $methods,
        );
    }

    /** @param  array<int, string>  $columns */
    private function methodName(array $columns): string
    {
        return 'ensureUnique' . implode('And', array_map(
            static fn (string $c): string => preg_replace('/[^A-Za-z0-9]/', '', Str::studly($c)) ?? '',
            $columns,
        ));
    }

    /** @param  array<int, string>  $columns */
    private function body(string $variable, array $columns, string $label): string
    {
        $values = implode(', ', array_map(
            static fn (string $c): string => "'{$c}' => {$variable}->{$c}",
            $columns,
        ));
        $dirty = implode(', ', array_map(static fn (string $c): string => "'{$c}'", $columns));
        $first = $columns[0];

        return <<<PHP
        \$values = [{$values}];

        if (in_array(null, \$values, true) || ({$variable}->exists && ! {$variable}->isDirty([{$dirty}]))) {
            return;
        }

        \$taken = {$variable}->newQueryWithoutScopes()
            ->where(\$values)
            ->when({$variable}->exists, fn (\$query) => \$query->whereKeyNot({$variable}->getKey()))
            ->exists();

        if (\$taken) {
            throw ValidationException::withMessages(['{$first}' => __('validation.unique', ['attribute' => '{$label}'])]);
        }
        PHP;
    }
}
