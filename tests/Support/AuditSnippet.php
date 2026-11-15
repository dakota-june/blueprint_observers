<?php

declare(strict_types=1);

namespace Dakotajune\Blueclues\Tests\Support;

use Dakotajune\Blueclues\Snippets\Snippet;
use Dakotajune\Blueclues\Snippets\SnippetContext;
use Dakotajune\Blueclues\Snippets\SnippetOutput;

final class AuditSnippet implements Snippet
{
    public function name(): string
    {
        return 'audit';
    }

    public function supports(string $event): bool
    {
        return in_array($event, ['creating', 'updated'], true);
    }

    public function render(SnippetContext $context): SnippetOutput
    {
        $extra = $context->hasColumn('title') ? "\n\$title = {$context->variableName()}->title;" : '';

        return new SnippetOutput(
            "Log::info('{$context->event}', ['id' => {$context->variableName()}->getKey()]);" . $extra,
            ['Illuminate\\Support\\Facades\\Log'],
        );
    }
}
