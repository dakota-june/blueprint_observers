<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Models;

/**
 * The `validation:` key of an observer, as written in the draft.
 *
 *   validation: false          -> enabled = false: this observer does not validate
 *   validation: true / ~       -> enabled = true:  rules derived from the model
 *   validation: { col: ... }   -> enabled = true:  derived rules, with these columns overridden
 *
 * An observer without the key has no spec at all (config decides).
 *
 * Override values: a list of rules replaces the derived rules of that column; the word `derived` inside the
 * list stands for them (`[derived, 'min:3']`); `false` removes validation from the column.
 */
final class ValidationSpec
{
    public const DERIVED = 'derived';

    /** @param  array<string, array<int, string>|false>  $overrides  column => rules, or false for none */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly array $overrides = [],
    ) {}
}
