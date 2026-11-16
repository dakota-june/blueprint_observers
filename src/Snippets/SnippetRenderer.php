<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Snippets;

use Dakotajune\BlueprintObservers\Lexers\ObserverLexer;
use ParseError;

/**
 * Runs the snippets opted into for one event and returns the finished method body, imports and any extra
 * methods the snippets asked for.
 * Every snippet's output is syntax checked on its own, so a broken snippet is reported by name instead
 * of surfacing later as a broken observer file.
 */
final class SnippetRenderer
{
    public function __construct(private readonly SnippetRegistry $registry) {}

    /**
     * @param  array<int, string>  $names
     * @return array{body: string, imports: array<int, string>, methods: array<string, SnippetMethod>}
     *         body is unindented; "" when there are no snippets. methods is keyed by method name.
     */
    public function render(array $names, SnippetContext $context): array
    {
        $bodies = [];
        $imports = [];
        $methods = [];

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

            foreach ($output->methods as $method) {
                $method = new SnippetMethod($method->name, $this->dedent($method->body), $method->summary, $method->returnType);

                $this->assertMethodValid($name, $context, $method);

                if (isset($methods[$method->name]) && $methods[$method->name]->body !== $method->body) {
                    throw new SnippetException(
                        "Snippets on {$context->observer}::{$context->event}() both define a method [{$method->name}] with different code.",
                    );
                }

                $methods[$method->name] = $method;
            }
        }

        return [
            'body' => implode("\n\n", $bodies),
            'imports' => array_values(array_unique($imports)),
            'methods' => $methods,
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

    private function assertMethodValid(string $name, SnippetContext $context, SnippetMethod $method): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method->name)) {
            throw new SnippetException("Snippet [{$name}] asked for a method with an invalid name [{$method->name}] ({$context->observer}).");
        }

        if (in_array(strtolower($method->name), array_map('strtolower', ObserverLexer::VALID_EVENTS), true)) {
            throw new SnippetException("Snippet [{$name}] asked for a method called [{$method->name}], which is an event name ({$context->observer}).");
        }

        $code = "<?php class __Check { protected function {$method->name}(\$model, {$context->variableName()}): {$method->returnType} {\n{$method->body}\n} }";

        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new SnippetException(
                "Snippet [{$name}] produced invalid PHP for {$context->observer}::{$method->name}(): {$e->getMessage()}",
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
