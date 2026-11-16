<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Support;

use Blueprint\Tree;
use Dakotajune\BlueprintObservers\Models\Guard;
use Dakotajune\BlueprintObservers\Snippets\SnippetException;
use Illuminate\Support\Str;

/**
 * Works out which guards an observer really has.
 *
 * Relationship method names are derived the way Blueprint names them for `hasMany`, `hasOne` and
 * `belongsToMany` (plain `Payment`, `Payment:alias`, `Payment.key:alias`, `Tag:&pivot`). Other relationship
 * types and fully qualified references are not derived; they can still be guarded by writing the method name.
 */
final class GuardResolver
{
    private const PLURAL = ['hasMany', 'belongsToMany'];

    private const KNOWN = ['hasMany', 'hasOne', 'belongsToMany'];

    /**
     * The relationships of a model in the draft, by method name.
     *
     * @return array<string, array{type: string, class: string, alias: string|null}>
     */
    public function relations(Tree $tree, object $model): array
    {
        $found = [];

        foreach ($model->relationships() as $type => $references) {
            if (! in_array($type, self::KNOWN, true)) {
                continue;
            }

            foreach ($references as $reference) {
                $reference = (string) $reference;

                if (str_contains($reference, '\\') || str_contains($reference, '/')) {
                    continue;
                }

                $info = $this->describe($tree, (string) $type, $reference);
                $found[$info['name']] = ['type' => $info['type'], 'class' => $info['class'], 'alias' => $info['alias']];
            }
        }

        return $found;
    }

    /**
     * Guards derived from the model's hasMany / hasOne relationships (the `auto` behaviour).
     *
     * @return array<string, Guard>
     */
    public function derive(Tree $tree, object $model): array
    {
        $guards = [];

        foreach ($this->relations($tree, $model) as $name => $info) {
            if (in_array($info['type'], ['hasMany', 'hasOne'], true)) {
                $guards[$name] = new Guard($this->actionFor($tree, $model, $info));
            }
        }

        return $guards;
    }

    /**
     * @param  object  $observer  Observer or NovaObserver
     * @return array<string, ResolvedGuard>  by relationship name, ordered restrict, nullify, cascade
     */
    public function resolve(Tree $tree, object $observer, ?object $model): array
    {
        $guards = [];

        if ($model !== null && $observer->autoGuards()) {
            $guards = $this->derive($tree, $model);
        }

        // Only read the model's relationships when there is something to resolve.
        $relations = $model !== null && ($observer->autoGuards() || $observer->guards() !== []) ? $this->relations($tree, $model) : [];

        foreach ($observer->guards() as $name => $guard) {
            if ($guard === null) {
                unset($guards[$name]);

                continue;
            }

            $guards[$name] = new Guard(
                $guard->action ?? $this->actionFor($tree, $model, $relations[$name] ?? null),
                $guard->trashed ?? ($guards[$name]->trashed ?? null),
            );
        }

        $resolved = [];

        foreach ($guards as $name => $guard) {
            $info = $relations[$name] ?? null;
            $action = $guard->action ?? Guard::RESTRICT;

            if ($action !== Guard::RESTRICT && $info !== null && ! in_array($info['type'], ['hasMany', 'hasOne'], true)) {
                throw new SnippetException(sprintf(
                    '%s guards [%s] with [%s], but it is a %s relationship; only hasMany and hasOne can be %s. Use restrict instead.',
                    $observer->name(),
                    $name,
                    $action,
                    $info['type'],
                    $action === Guard::CASCADE ? 'cascaded' : 'nullified',
                ));
            }

            $child = $info === null ? null : $tree->modelForContext($info['class']);
            $trashed = $guard->trashed ?? (string) config('blueprint_observers.guards.trashed', Guard::TRASHED_BLOCK);

            $resolved[$name] = new ResolvedGuard(
                $name,
                $action,
                $trashed !== Guard::TRASHED_IGNORE,
                $model === null ? null : (bool) $model->usesSoftDeletes(),
                $child === null ? null : (bool) $child->usesSoftDeletes(),
            );
        }

        $rank = [Guard::RESTRICT => 0, Guard::NULLIFY => 1, Guard::CASCADE => 2];
        uasort($resolved, static fn (ResolvedGuard $a, ResolvedGuard $b): int => $rank[$a->action] <=> $rank[$b->action]);

        return $resolved;
    }

    /**
     * What to do: the child's `ondelete:` if the draft says one, otherwise config `auto.guard_default`.
     *
     * @param  array{type: string, class: string, alias: string|null}|null  $info
     */
    private function actionFor(Tree $tree, ?object $model, ?array $info): string
    {
        $default = (string) config('blueprint_observers.auto.guard_default', Guard::RESTRICT);
        $default = in_array($default, Guard::ACTIONS, true) ? $default : Guard::RESTRICT;

        if ($model === null || $info === null) {
            return $default;
        }

        $child = $tree->modelForContext($info['class']);

        if ($child === null) {
            return $default;
        }

        $candidates = array_values(array_filter([
            $info['alias'] !== null ? Str::beforeLast($info['alias'], '_id') . '_id' : null,
            Str::snake($model->name()) . '_id',
        ]));

        foreach ($child->columns() as $column) {
            if (! in_array($column->name(), $candidates, true)) {
                continue;
            }

            foreach ($column->modifiers() as $modifier) {
                if (is_array($modifier) && key($modifier) === 'onDelete') {
                    return match (strtolower((string) current($modifier))) {
                        'cascade' => Guard::CASCADE,
                        'null' => Guard::NULLIFY,
                        'restrict', 'no_action' => Guard::RESTRICT,
                        default => $default,
                    };
                }
            }
        }

        return $default;
    }

    /** @return array{name: string, type: string, class: string, alias: string|null} */
    private function describe(Tree $tree, string $type, string $reference): array
    {
        $column = $reference;
        $method = Str::beforeLast($reference, '_id');
        $custom = false;
        $pivot = false;
        $class = null;
        $alias = null;

        if (str_contains($reference, ':')) {
            [$foreign, $column] = explode(':', $reference, 2);

            if (str_starts_with($column, '&')) {
                $pivot = true;
                $column = Str::after($column, '&');
                $method = $column;
            } else {
                $method = Str::beforeLast($column, '_id');
                $custom = true;
                $alias = $column;
            }

            if (str_contains($foreign, '.')) {
                $class = explode('.', $foreign)[0];
                $method = Str::lower($class);
                $custom = false;
                $alias = null;
            } else {
                $class = $foreign;
            }
        }

        $className = Str::studly($class ?? $method);

        if (in_array($type, self::PLURAL, true)) {
            if ($pivot || $custom) {
                $method = Str::plural($pivot ? $column : $method);
            } else {
                $related = $tree->modelForContext($className);
                $method = $related !== null && method_exists($related, 'pluralName') ? $related->pluralName() : Str::plural($method);
            }
        }

        return ['name' => Str::camel($method), 'type' => $type, 'class' => $className, 'alias' => $alias];
    }
}
