<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Support;

/** Renders generated observer methods, each carrying the `@generated blueprint_observers` ownership tag. */
final class ObserverMethodBuilder
{
    /**
     * An event method: `public function creating(Model $model): void`.
     *
     * @param  string  $body  Unindented statements; "" renders a `//` placeholder.
     * @return string  Method source at 4-space indent; the type hint is the editor's model placeholder.
     */
    public static function build(string $event, string $modelShort, string $variable, string $body): string
    {
        return self::render(
            "Handle the {$modelShort} \"{$event}\" event.",
            "    public function {$event}(" . ObserverClassEditor::MODEL_PLACEHOLDER . " \${$variable}): void",
            $body,
        );
    }

    /**
     * A method a snippet asked for, next to the event methods: `protected function ensureUniqueNumber(Model $model): void`.
     * It is written, rewritten and removed exactly like an event method.
     */
    public static function buildHelper(string $name, string $summary, string $variable, string $body, string $returnType = 'void'): string
    {
        return self::render(
            $summary,
            "    protected function {$name}(" . ObserverClassEditor::MODEL_PLACEHOLDER . " \${$variable}): {$returnType}",
            $body,
        );
    }

    private static function render(string $summary, string $signature, string $body): string
    {
        $lines = [
            '    /**',
            "     * {$summary}",
            '     *',
            '     * ' . ObserverClassEditor::MARKER . ' Rewritten on every build. Remove this tag to take ownership of the method.',
            '     */',
            $signature,
            '    {',
        ];

        if (trim($body) === '') {
            $lines[] = '        //';
        } else {
            foreach (explode("\n", $body) as $line) {
                $lines[] = trim($line) === '' ? '' : '        ' . $line;
            }
        }

        $lines[] = '    }';

        return implode("\n", $lines);
    }
}
