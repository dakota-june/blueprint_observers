<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Snippets;

/**
 * An extra method a snippet asks for, next to the event method it adds code to.
 *
 * It is written like every other generated method: `protected function {name}(Model $model): void`, carrying
 * the `@generated blueprint_observers` tag, rewritten on every build and removed when nothing asks for it any
 * more. The event method's body is expected to call it.
 */
final class SnippetMethod
{
    /**
     * @param  string  $name  A valid method name that is not an event name.
     * @param  string  $body  PHP statements, no braces, any indentation. The model is passed in as the
     *                        context's variable (see SnippetContext::variableName()).
     * @param  string  $summary  One line for the docblock.
     * @param  string  $returnType  PHP return type, `void` unless the method hands something back.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $body,
        public readonly string $summary = '',
        public readonly string $returnType = 'void',
    ) {}
}
