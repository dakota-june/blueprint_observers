<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Lexers;

use Blueprint\Contracts\Lexer;
use Dakotajune\BlueprintObservers\Models\Guard;
use Dakotajune\BlueprintObservers\Models\Observer;
use Dakotajune\BlueprintObservers\Models\ValidationSpec;
use Dakotajune\BlueprintObservers\Snippets\SnippetRegistry;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reads the top-level `observers:` section of a draft. Blueprint's own lexers ignore this section,
 * so no other lexer needs to be decorated or reordered.
 *
 *   observers:
 *     PaymentObserver:
 *       model: Payment            # optional, defaults to the observer name minus "Observer"
 *       afterCommit: true         # optional
 *       events:                   # event => snippets opted into for that event
 *         creating: [audit]       # snippets are ALWAYS written inside [...]
 *         updated:                # no snippets
 *         deleted: false          # do not generate this event
 *       guards:                   # what to do about relationships when the model is deleted
 *         payments: restrict      # restrict | cascade | nullify
 *         notes: { action: cascade, trashed: ignore }
 *         auto: true              # also derive guards from the model's hasMany / hasOne (`guards: auto`)
 *         comments: false         # ... except this one
 *       validation:               # validate the model with Laravel's validator on every save
 *         number: [required, string, max:20]    # replace the rules derived from `models:` for a column
 *         notes: [derived, min:3]               # derived rules, plus these
 *         legacy_code: false                    # no validation for this column
 *       # validation: true | false              # on with derived rules / off for this observer
 *
 * `events` may also be a flow list ([creating, updated]) or "creating, updated"; omit it for the defaults.
 *
 * Snippet lists must use [...]. Blueprint strips leading list dashes before YAML is read, so a block list
 * would reach us as a bare string; that is rejected with a hint rather than guessed at.
 */
final class ObserverLexer implements Lexer
{
    public const VALID_EVENTS = [
        'retrieved', 'creating', 'created', 'updating', 'updated',
        'saving', 'saved', 'deleting', 'deleted', 'trashed',
        'forceDeleting', 'forceDeleted', 'restoring', 'restored', 'replicating',
    ];

    public function __construct(private readonly SnippetRegistry $snippets) {}

    /** @return array{observers?: array<string, Observer>} */
    public function analyze(array $tokens): array
    {
        $section = $tokens['observers'] ?? null;

        if (! is_array($section) || $section === []) {
            return [];
        }

        $observers = [];

        foreach ($section as $key => $definition) {
            $name = $this->observerName((string) $key);

            if (isset($observers[$name])) {
                throw new InvalidArgumentException("Observer [{$name}] is defined more than once in the draft.");
            }

            $definition = is_array($definition) ? $definition : [];

            [$guards, $autoGuards] = $this->guards($name, $definition['guards'] ?? null);

            $observers[$name] = new Observer(
                name: $name,
                modelName: $this->modelName((string) ($definition['model'] ?? Str::beforeLast($name, 'Observer'))),
                events: $this->events($name, $definition['events'] ?? null),
                afterCommit: $this->afterCommit($definition['afterCommit'] ?? null),
                guards: $guards,
                autoGuards: $autoGuards,
                validation: array_key_exists('validation', $definition) ? $this->validation($name, $definition['validation']) : null,
            );
        }

        return ['observers' => $observers];
    }

    private function observerName(string $key): string
    {
        $name = Str::studly($key);

        return str_ends_with($name, 'Observer') ? $name : $name . 'Observer';
    }

    private function modelName(string $reference): string
    {
        return implode('\\', array_map(
            static fn (string $segment): string => Str::studly($segment),
            explode('\\', trim($reference, '\\')),
        ));
    }

    /** @return array<string, array<int, string>> */
    private function events(string $observer, mixed $raw): array
    {
        if ($raw === null || $raw === true || $raw === '' || $raw === []) {
            return array_fill_keys(array_values((array) config('blueprint_observers.default_events', ['creating', 'updating', 'deleted'])), []);
        }

        $map = [];

        if (is_string($raw)) {
            foreach ($this->words($raw) as $event) {
                $this->addEvent($map, $observer, $event, null);
            }

            return $map;
        }

        if (! is_array($raw)) {
            throw new InvalidArgumentException("Observer [{$observer}] has an unreadable events definition.");
        }

        foreach ($raw as $key => $value) {
            if (is_string($key)) {                      // creating: [audit]
                $this->addEvent($map, $observer, $key, $value);
            } elseif (is_array($value)) {               // - creating: [audit]
                foreach ($value as $event => $snippets) {
                    $this->addEvent($map, $observer, (string) $event, $snippets);
                }
            } else {                                    // - creating   /   "creating updated"
                foreach ($this->words((string) $value) as $event) {
                    $this->addEvent($map, $observer, $event, null);
                }
            }
        }

        // `event: false` switches an event off; it was only kept in the map to catch duplicates.
        return array_filter($map, static fn (array|false $snippets): bool => $snippets !== false);
    }

    /** @param  array<string, array<int, string>|false>  $map */
    private function addEvent(array &$map, string $observer, string $event, mixed $snippets): void
    {
        $event = trim($event);

        if (! in_array($event, self::VALID_EVENTS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Observer [%s] has unknown event [%s]. Valid events: %s.',
                $observer,
                $event,
                implode(', ', self::VALID_EVENTS),
            ));
        }

        if (isset($map[$event])) {
            throw new InvalidArgumentException("Observer [{$observer}] lists the [{$event}] event more than once.");
        }

        if ($snippets === false) {
            $map[$event] = false;                       // explicitly switched off

            return;
        }

        $names = match (true) {
            $snippets === null || $snippets === true => [],
            is_array($snippets) => array_map(static fn (mixed $name): string => is_string($name) ? trim($name) : '', $snippets),
            default => throw new InvalidArgumentException(
                "Observer [{$observer}] event [{$event}]: snippets must be written inside square brackets, "
                . "e.g. {$event}: [audit]. Use `{$event}:` for none or `{$event}: false` to skip the event.",
            ),
        };

        if (in_array('', $names, true)) {
            throw new InvalidArgumentException("Observer [{$observer}] event [{$event}] has an empty or non-text snippet name.");
        }

        $names = array_values($names);

        foreach ($names as $i => $name) {
            if (! $this->snippets->has($name)) {
                throw new InvalidArgumentException("Observer [{$observer}] event [{$event}] uses snippet [{$name}], which is not registered.");
            }

            if (in_array($name, array_slice($names, 0, $i), true)) {
                throw new InvalidArgumentException("Observer [{$observer}] event [{$event}] lists snippet [{$name}] more than once.");
            }

            if (! $this->snippets->get($name)->supports($event)) {
                throw new InvalidArgumentException("Snippet [{$name}] does not support the [{$event}] event ({$observer}).");
            }
        }

        $map[$event] = $names;
    }

    private function validation(string $observer, mixed $raw): ValidationSpec
    {
        if ($raw === false) {
            return new ValidationSpec(false);
        }

        if ($raw === null || $raw === true || $raw === '' || $raw === []) {
            return new ValidationSpec(true);
        }

        if (! is_array($raw)) {
            throw new InvalidArgumentException("Observer [{$observer}] validation must be true, false or a map of column => rules.");
        }

        $overrides = [];

        foreach ($raw as $column => $value) {
            $column = trim((string) $column);

            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
                throw new InvalidArgumentException("Observer [{$observer}] validation: [{$column}] is not a column name.");
            }

            if (isset($overrides[$column])) {
                throw new InvalidArgumentException("Observer [{$observer}] validation lists [{$column}] more than once.");
            }

            $overrides[$column] = $this->rules($observer, $column, $value);
        }

        return new ValidationSpec(true, $overrides);
    }

    /** @return array<int, string>|false */
    private function rules(string $observer, string $column, mixed $value): array|false
    {
        if ($value === false) {
            return false;
        }

        if ($value === null || $value === true) {
            return [ValidationSpec::DERIVED];
        }

        // `required|string|max:20`, or a list. Use a list (and quotes) when a rule contains a comma or a pipe.
        $items = is_string($value) ? explode('|', $value) : (is_array($value) ? $value : null);

        if ($items === null) {
            throw new InvalidArgumentException("Observer [{$observer}] validation [{$column}] must be a list of rules, a `a|b` string or false.");
        }

        $rules = [];

        foreach ($items as $item) {
            if (! is_string($item) && ! is_int($item) && ! is_float($item)) {
                throw new InvalidArgumentException("Observer [{$observer}] validation [{$column}] has a rule that is not text.");
            }

            $item = trim((string) $item);

            if ($item === '') {
                throw new InvalidArgumentException("Observer [{$observer}] validation [{$column}] has an empty rule.");
            }

            $rules[] = $item;
        }

        return $rules;
    }

    /**
     * @return array{0: array<string, Guard|null>, 1: bool}  guards by relationship name, and whether `auto` was asked for
     */
    private function guards(string $observer, mixed $raw): array
    {
        if ($raw === null || $raw === false || $raw === '' || $raw === []) {
            return [[], false];
        }

        if ($raw === true) {
            return [[], true];
        }

        $guards = [];
        $auto = false;

        $add = function (string $relationship, mixed $value) use (&$guards, &$auto, $observer): void {
            $relationship = trim($relationship);

            if (strtolower($relationship) === 'auto') {
                $auto = filter_var($value ?? true, FILTER_VALIDATE_BOOLEAN);

                return;
            }

            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $relationship)) {
                throw new InvalidArgumentException("Observer [{$observer}] guards: [{$relationship}] is not a relationship method name.");
            }

            $name = Str::camel($relationship);

            if (array_key_exists($name, $guards)) {
                throw new InvalidArgumentException("Observer [{$observer}] guards the [{$name}] relationship more than once.");
            }

            $guards[$name] = $this->guard($observer, $name, $value);
        };

        if (is_string($raw)) {
            foreach ($this->words($raw) as $word) {
                $add($word, null);
            }

            return [$guards, $auto];
        }

        if (! is_array($raw)) {
            throw new InvalidArgumentException("Observer [{$observer}] has an unreadable guards definition.");
        }

        foreach ($raw as $key => $value) {
            if (is_string($key)) {                      // payments: restrict
                $add($key, $value);
            } elseif (is_array($value)) {               // - payments: restrict
                foreach ($value as $relationship => $action) {
                    $add((string) $relationship, $action);
                }
            } else {                                    // - payments   /   "payments notes"
                foreach ($this->words((string) $value) as $word) {
                    $add($word, null);
                }
            }
        }

        return [$guards, $auto];
    }

    private function guard(string $observer, string $relationship, mixed $value): ?Guard
    {
        if ($value === false) {
            return null;                                // switches an auto-derived guard off
        }

        if ($value === null || $value === true) {
            return new Guard();
        }

        if (is_string($value)) {
            return new Guard($this->action($observer, $relationship, $value));
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("Observer [{$observer}] guard [{$relationship}] is unreadable.");
        }

        $unknown = array_values(array_diff(array_map('strval', array_keys($value)), ['action', 'trashed']));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Observer [%s] guard [%s] has unknown key [%s]. Valid keys: action, trashed.',
                $observer,
                $relationship,
                implode(', ', $unknown),
            ));
        }

        $trashed = $value['trashed'] ?? null;

        if ($trashed !== null && ! in_array($trashed, [Guard::TRASHED_BLOCK, Guard::TRASHED_IGNORE], true)) {
            throw new InvalidArgumentException("Observer [{$observer}] guard [{$relationship}]: trashed must be block or ignore.");
        }

        $action = $value['action'] ?? null;

        return new Guard($action === null ? null : $this->action($observer, $relationship, (string) $action), $trashed);
    }

    private function action(string $observer, string $relationship, string $action): string
    {
        $action = strtolower(trim($action));

        if (! in_array($action, Guard::ACTIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Observer [%s] guard [%s] has unknown action [%s]. Valid actions: %s.',
                $observer,
                $relationship,
                $action,
                implode(', ', Guard::ACTIONS),
            ));
        }

        return $action;
    }

    /** @return array<int, string> */
    private function words(string $value): array
    {
        return preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function afterCommit(mixed $raw): bool
    {
        if ($raw === null) {
            return (bool) config('blueprint_observers.after_commit', true);
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? (bool) config('blueprint_observers.after_commit', true);
    }
}
