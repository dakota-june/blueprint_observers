<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Snippets;

final class SnippetOutput
{
    /**
     * @param  string  $body  PHP statements for the method body. No surrounding braces, any indentation.
     * @param  array<int, string>  $imports  Fully qualified class names the body needs imported.
     */
    public function __construct(
        public readonly string $body,
        public readonly array $imports = [],
    ) {}
}
