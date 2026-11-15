<?php

declare(strict_types=1);

namespace Dakotajune\Blueclues\Snippets;

/**
 * A named, opt-in piece of code contributed to a generated observer method at build time.
 *
 * Draft usage (per event):
 *
 *   observers:
 *     Invoice:
 *       events:
 *         creating: [audit]
 */
interface Snippet
{
    /** Unique name used in the draft, e.g. "audit". Letters, digits, "_" and "-"; must start with a letter. */
    public function name(): string;

    /** Whether this snippet may be attached to the given observer event (e.g. "creating"). */
    public function supports(string $event): bool;

    public function render(SnippetContext $context): SnippetOutput;
}
