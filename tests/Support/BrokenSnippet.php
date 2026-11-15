<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Tests\Support;

use Dakotajune\BlueprintObservers\Snippets\Snippet;
use Dakotajune\BlueprintObservers\Snippets\SnippetContext;
use Dakotajune\BlueprintObservers\Snippets\SnippetOutput;

final class BrokenSnippet implements Snippet
{
    public function name(): string
    {
        return 'broken';
    }

    public function supports(string $event): bool
    {
        return true;
    }

    public function render(SnippetContext $context): SnippetOutput
    {
        return new SnippetOutput('this is not ( php');
    }
}
