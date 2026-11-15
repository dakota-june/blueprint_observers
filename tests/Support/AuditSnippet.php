<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Tests\Support;

use Dakotajune\BlueprintObservers\Snippets\Snippet;
use Dakotajune\BlueprintObservers\Snippets\SnippetContext;
use Dakotajune\BlueprintObservers\Snippets\SnippetOutput;

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
