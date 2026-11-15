<?php

declare(strict_types=1);

namespace Dakotajune\Blueclues\Tests\Support;

use Dakotajune\Blueclues\Snippets\Snippet;
use Dakotajune\Blueclues\Snippets\SnippetContext;
use Dakotajune\Blueclues\Snippets\SnippetOutput;

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
