<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Snippets;

use ParseError;

/**
 * Runs the snippets opted into for one event and returns the finished method body plus imports.
 * Every snippet's output is syntax checked on its own, so a broken snippet is reported by name instead
 * of surfacing later as a broken observer file.
 */
final class SnippetRenderer
{
    public function __construct(private readonly SnippetRegistry $registry) {}

    /**
     * @param  array<int, string>  $names
     * @return array{body: string, imports: array<int, string>}  body is unindented; "" when there are no snippets
     */
    public function render(array $names, SnippetContext $context): array
    {
        $bodies = [];
        $imports = [];

        foreach ($names as $name) {
            $snippet = $this->registry->get($name);

            if (! $snippet->supports($context->event)) {
                throw new SnippetException(
                    "Snippet [{$name}] does not support the [{$context->event}] event ({$context->observer}).",
                );
            }

            $output = $snippet->render($context);
            $body = $this->dedent($output->body);

            $this->assertValid($name, $context, $body);

            if ($body !== '') {
                $bodies[] = $body;
            }

            foreach ($output->imports as $import) {
                $imports[] = ltrim($import, '\\');
            }
        }

        return [
            'body' => implode("\n\n", $bodies),
            'imports' => array_values(array_unique($imports)),
        ];
    }

    private function assertValid(string $name, SnippetContext $context, string $body): void
    {
        $code = "<?php class __Check { public function __check(\$model, {$context->variableName()}): void {\n{$body}\n} }";

        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new SnippetException(
                "Snippet [{$name}] produced invalid PHP for {$context->observer}::{$context->event}(): {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    private function dedent(string $body): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", trim($body, "\n")));
        $indent = null;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $current = strlen($line) - strlen(ltrim($line, " \t"));
            $indent = $indent === null ? $current : min($indent, $current);
        }

        $indent ??= 0;

        return rtrim(implode("\n", array_map(
            static fn (string $line): string => trim($line) === '' ? '' : substr($line, $indent),
            $lines,
        )));
    }
}
