<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Snippets;

use InvalidArgumentException;
use LogicException;

/**
 * The single, explicit list of snippets known to the build. Nothing is discovered or scanned:
 * a snippet exists only if a service provider registered it.
 */
final class SnippetRegistry
{
    /** @var array<string, Snippet> */
    private array $snippets = [];

    public function register(Snippet $snippet): void
    {
        $name = $snippet->name();

        if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name)) {
            throw new InvalidArgumentException(
                "Snippet name [{$name}] is invalid: use letters, digits, \"_\" and \"-\", starting with a letter.",
            );
        }

        if (isset($this->snippets[$name])) {
            throw new LogicException("A snippet named [{$name}] is already registered.");
        }

        $this->snippets[$name] = $snippet;
    }

    public function has(string $name): bool
    {
        return isset($this->snippets[$name]);
    }

    public function get(string $name): Snippet
    {
        return $this->snippets[$name]
            ?? throw new InvalidArgumentException("Snippet [{$name}] is not registered.");
    }
}
