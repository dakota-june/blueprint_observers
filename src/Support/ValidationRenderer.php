<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Support;

use Dakotajune\BlueprintObservers\Snippets\SnippetMethod;
use Dakotajune\BlueprintObservers\Snippets\SnippetOutput;
use Illuminate\Support\Str;

/**
 * Turns validation rules into a `saving` body plus two generated methods:
 *
 *   validationRules($model)    the rules, one line per column (take it over to hand-tune them)
 *   validateAttributes($model) runs Laravel's Validator and throws a ValidationException
 *
 * Built for Nova (and any form): errors are keyed by column name; a `*_id` column's errors are also keyed by the
 * relationship name, which is how Nova names a belongsTo field; an update only validates what changed, so an
 * old row with a legacy value cannot block an edit of another column; a create validates the attributes
 * the model holds, so a column with a database default is not "required".
 */
final class ValidationRenderer
{
    /** @param  array<string, array<int, string>>  $rules  column => rule expressions */
    public function render(array $rules, string $variable, string $modelClass): SnippetOutput
    {
        $v = '$' . $variable;
        $model = Str::lower(Str::snake($modelClass, ' '));
        $imports = ['Illuminate\\Support\\Facades\\Validator', 'Illuminate\\Validation\\ValidationException'];

        $lines = ['return ['];

        foreach ($rules as $column => $expressions) {
            $lines[] = '    ' . var_export((string) $column, true) . ' => [' . implode(', ', $expressions) . '],';

            if (array_filter($expressions, static fn (string $e): bool => str_starts_with($e, 'Rule::'))) {
                $imports[] = 'Illuminate\\Validation\\Rule';
            }
        }

        $lines[] = '];';

        $methods = [
            new SnippetMethod('validationRules', implode("\n", $lines), "Rules the {$model} is validated against whenever it is saved.", 'array'),
            new SnippetMethod('validateAttributes', $this->validate($v, $this->hasRelationKeys($rules)), "Validate the {$model} with Laravel's validator before it is saved."),
        ];

        return new SnippetOutput("\$this->validateAttributes({$v});", array_values(array_unique($imports)), $methods);
    }

    /** @param  array<string, array<int, string>>  $rules */
    private function hasRelationKeys(array $rules): bool
    {
        foreach (array_keys($rules) as $column) {
            if (str_ends_with((string) $column, '_id') && strlen((string) $column) > 3) {
                return true;
            }
        }

        return false;
    }

    private function validate(string $v, bool $relationKeys): string
    {
        $lines = [
            "\$rules = \$this->validationRules({$v});",
            '',
            "if ({$v}->exists) {",
            '    // Only what changed: an update must not fail over columns it does not touch.',
            "    \$data = {$v}->getDirty();",
            '    $rules = array_intersect_key($rules, $data);',
            '} else {',
            "    \$data = {$v}->getAttributes();",
            '}',
            '',
            'if ($rules === []) {',
            '    return;',
            '}',
            '',
            '$validator = Validator::make($data, $rules);',
            '',
            'if ($validator->fails()) {',
            '    $errors = $validator->errors()->toArray();',
        ];

        if ($relationKeys) {
            array_push(
                $lines,
                '',
                '    // Nova names a belongsTo field after its relationship ("invoice"), not its column ("invoice_id").',
                '    foreach ($errors as $attribute => $messages) {',
                "        if (str_ends_with(\$attribute, '_id') && strlen(\$attribute) > 3) {",
                "            \$errors[substr(\$attribute, 0, -3)] ??= \$messages;",
                '        }',
                '    }',
                '',
            );
        }

        array_push($lines, '    throw ValidationException::withMessages($errors);', '}');

        return implode("\n", $lines);
    }
}
