<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Support;

use Blueprint\Tree;
use Dakotajune\BlueprintObservers\Models\Observer;
use Dakotajune\BlueprintObservers\Snippets\UniqueSnippet;
use Illuminate\Support\Str;

/**
 * Adds observers nobody listed under `observers:`.
 *
 * Off by default (`blueprint_observers.auto.enabled`). When on, every model in the draft that no listed
 * observer covers gets an observer for whatever it has to enforce:
 *
 *  - unique column / unique index (`auto.unique`): the built-in `unique` snippet on creating and updating
 *  - hasMany / hasOne relationships (`auto.guards`): a guard per relationship on deleting (restrict by default,
 *    or what the child's `ondelete:` says; see GuardResolver)
 *  - columns (`auto.validation`, and `validation.enabled`): validation on saving, derived from the model
 *
 * An observer you list yourself is never changed: it is yours, and you opt in per event with `[unique]`.
 * Auto observers are never after-commit: the check has to run before the row is written.
 */
final class AutoObservers
{
    public const EVENTS = ['creating', 'updating'];

    private readonly GuardResolver $guards;

    private readonly ValidationRules $validation;

    public function __construct(?GuardResolver $guards = null)
    {
        $this->guards = $guards ?? new GuardResolver();
        $this->validation = new ValidationRules();
    }

    /**
     * @param  array<string, Observer>  $listed  observer name => observer, as the lexer produced them
     * @return array<string, Observer>
     */
    public function merge(Tree $tree, array $listed): array
    {
        if (! config('blueprint_observers.auto.enabled', false)) {
            return $listed;
        }

        $wantsUnique = (bool) config('blueprint_observers.auto.unique', true);
        $wantsGuards = (bool) config('blueprint_observers.auto.guards', true);
        $wantsValidation = (bool) config('blueprint_observers.auto.validation', true)
            && (bool) config('blueprint_observers.validation.enabled', false);

        $covered = [];
        foreach ($listed as $observer) {
            $covered[ltrim($observer->modelName(), '\\')] = true;
        }

        foreach ($tree->toArray()['models'] ?? [] as $name => $model) {
            $name = (string) $name;
            $observerName = Str::studly(class_basename($name)) . 'Observer';

            if (isset($covered[$name]) || isset($listed[$observerName])) {
                continue;
            }

            if (method_exists($model, 'isPivot') && $model->isPivot()) {
                continue;
            }

            $events = [];

            if ($wantsUnique && UniqueConstraints::for($model) !== []) {
                $events = array_fill_keys(self::EVENTS, [UniqueSnippet::NAME]);
            }

            $guarded = $wantsGuards && $this->guards->derive($tree, $model) !== [];

            if ($guarded) {
                $events['deleting'] = [];
            }

            if ($wantsValidation && $this->validation->derive($model) !== []) {
                $events['saving'] = [];
            }

            if ($events === []) {
                continue;
            }

            $listed[$observerName] = new Observer(
                name: $observerName,
                modelName: $name,
                events: $events,
                afterCommit: false,
                autoGuards: $guarded,
            );
        }

        return $listed;
    }
}
