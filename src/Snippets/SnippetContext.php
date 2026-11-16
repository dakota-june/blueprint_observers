<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Snippets;

use Dakotajune\BlueprintObservers\Support\UniqueConstraints;

/**
 * What a snippet may know when it renders. Read-only.
 */
final class SnippetContext
{
    /** @param  object|null  $model  The draft's Blueprint\Models\Model for the observed model, if it is in the draft. */
    public function __construct(
        public readonly string $observer,
        public readonly string $observerFqcn,
        public readonly string $model,
        public readonly string $modelFqcn,
        public readonly string $variable,
        public readonly string $event,
        private readonly ?object $blueprintModel = null,
    ) {}

    /** True when the observed model is defined in the draft and has this column. */
    public function hasColumn(string $name): bool
    {
        return $this->blueprintModel !== null && $this->blueprintModel->hasColumn($name);
    }

    /** @return array<int, string> Column names of the observed model (empty when it is not in the draft). */
    public function columns(): array
    {
        if ($this->blueprintModel === null) {
            return [];
        }

        return array_values(array_map(
            static fn (object $column): string => $column->name(),
            $this->blueprintModel->columns(),
        ));
    }

    /**
     * What must be unique on the observed model: `unique` columns and unique indexes, as lists of column
     * names (a composite index is one entry). Empty when the model is not in the draft.
     *
     * @return array<int, array<int, string>>
     */
    public function uniqueConstraints(): array
    {
        return $this->blueprintModel === null ? [] : UniqueConstraints::for($this->blueprintModel);
    }

    /** The PHP variable the method receives the model in, including the "$", e.g. "$invoice". */
    public function variableName(): string
    {
        return '$' . $this->variable;
    }
}
