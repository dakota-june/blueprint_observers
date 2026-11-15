<?php

declare(strict_types=1);

namespace Dakotajune\Blueclues\Support;

/** Renders one generated observer method, carrying the `@generated blueclues` ownership tag. */
final class ObserverMethodBuilder
{
    /**
     * @param  string  $body  Unindented statements; "" renders a `//` placeholder.
     * @return string  Method source at 4-space indent; the type hint is the editor's model placeholder.
     */
    public static function build(string $event, string $modelShort, string $variable, string $body): string
    {
        $lines = [
            '    /**',
            "     * Handle the {$modelShort} \"{$event}\" event.",
            '     *',
            '     * ' . ObserverClassEditor::MARKER . ' Rewritten on every build. Remove this tag to take ownership of the method.',
            '     */',
            "    public function {$event}(" . ObserverClassEditor::MODEL_PLACEHOLDER . " \${$variable}): void",
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
