<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Support;

use Dakotajune\BlueprintObservers\Models\Guard;
use Dakotajune\BlueprintObservers\Snippets\SnippetMethod;
use Dakotajune\BlueprintObservers\Snippets\SnippetOutput;
use Illuminate\Support\Str;

/**
 * Turns resolved guards into the body of the `deleting` method plus one helper method per relationship
 * (`restrictPayments`, `nullifyNotes`, `cascadeLineItems`).
 *
 * Like a foreign key, a guard acts when the row is really removed: on a model that uses soft deletes it is
 * skipped for a soft delete and runs on forceDelete().
 */
final class GuardRenderer
{
    /** @param  array<string, ResolvedGuard>  $guards */
    public function render(array $guards, string $variable, string $modelClass): SnippetOutput
    {
        $v = '$' . $variable;
        $calls = [];
        $methods = [];
        $imports = [];

        foreach ($guards as $guard) {
            $name = $guard->action . Str::studly($guard->name);
            $calls[] = "\$this->{$name}({$v});";
            $methods[] = new SnippetMethod($name, $this->body($guard, $v, $modelClass), $this->summary($guard, $modelClass));

            if ($guard->action === Guard::RESTRICT) {
                $imports[] = 'Illuminate\\Validation\\ValidationException';
            }

            if ($this->needsRuntimeTrashedCheck($guard)) {
                $imports[] = 'Illuminate\\Database\\Eloquent\\SoftDeletes';
            }
        }

        return new SnippetOutput(implode("\n", $calls), array_values(array_unique($imports)), $methods);
    }

    private function needsRuntimeTrashedCheck(ResolvedGuard $guard): bool
    {
        return $guard->includeTrashed && $guard->childSoft === null;
    }

    private function summary(ResolvedGuard $guard, string $modelClass): string
    {
        $model = Str::lower(Str::snake($modelClass, ' '));
        $related = Str::lower(Str::snake($guard->name, ' '));

        return match ($guard->action) {
            Guard::RESTRICT => "Block deleting the {$model} while it still has {$related}.",
            Guard::NULLIFY => "Clear the foreign key of the {$model}'s {$related} when it is deleted.",
            default => "Delete the {$model}'s {$related} when it is deleted.",
        };
    }

    private function body(ResolvedGuard $guard, string $v, string $modelClass): string
    {
        $lines = [];

        if ($guard->parentSoft === true) {
            array_push($lines, "if (! {$v}->isForceDeleting()) {", '    return;', '}', '');
        } elseif ($guard->parentSoft === null) {
            array_push($lines, "if (method_exists({$v}, 'isForceDeleting') && ! {$v}->isForceDeleting()) {", '    return;', '}', '');
        }

        $lines[] = "\$relation = {$v}->{$guard->name}();";

        if ($guard->includeTrashed && $guard->childSoft === true) {
            $lines[] = '$relation->withTrashed();';
        } elseif ($this->needsRuntimeTrashedCheck($guard)) {
            array_push($lines, '', 'if (in_array(SoftDeletes::class, class_uses_recursive($relation->getRelated()), true)) {', '    $relation->withTrashed();', '}');
        }

        $lines[] = '';

        switch ($guard->action) {
            case Guard::RESTRICT:
                $model = Str::lower(Str::snake($modelClass, ' '));
                $related = Str::lower(Str::snake($guard->name, ' '));
                array_push(
                    $lines,
                    'if ($relation->exists()) {',
                    "    throw ValidationException::withMessages(['{$guard->name}' => __('Cannot delete this {$model} while it still has {$related}.')]);",
                    '}',
                );
                break;

            case Guard::NULLIFY:
                $lines[] = '$relation->update([$relation->getForeignKeyName() => null]);';
                break;

            default:
                $lines[] = match ($guard->childSoft) {
                    true => '$relation->get()->each->forceDelete();',
                    false => '$relation->get()->each->delete();',
                    null => '$relation->get()->each(fn ($child) => method_exists($child, \'forceDelete\') ? $child->forceDelete() : $child->delete());',
                };
        }

        return implode("\n", $lines);
    }
}
