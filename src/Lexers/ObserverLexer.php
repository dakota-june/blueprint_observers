<?php

declare(strict_types=1);

namespace Dakotajune\Blueclues\Lexers;

use Blueprint\Contracts\Lexer;
use Dakotajune\Blueclues\Models\Observer;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reads the top-level `observers:` section of a draft. Blueprint's own lexers ignore this section,
 * so no other lexer needs to be decorated or reordered.
 *
 *   observers:
 *     PaymentObserver:
 *       model: Payment          # optional, defaults to the observer name minus "Observer"
 *       afterCommit: true       # optional
 *       events: [creating, updated, deleted]   # or "creating, updated" / omitted for defaults
 */
final class ObserverLexer implements Lexer
{
    public const VALID_EVENTS = [
        'retrieved', 'creating', 'created', 'updating', 'updated',
        'saving', 'saved', 'deleting', 'deleted', 'trashed',
        'forceDeleting', 'forceDeleted', 'restoring', 'restored', 'replicating',
    ];

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

            $model = $this->modelName((string) ($definition['model'] ?? Str::beforeLast($name, 'Observer')));

            $observers[$name] = new Observer(
                name: $name,
                modelName: $model,
                events: $this->events($name, $definition['events'] ?? null),
                afterCommit: $this->afterCommit($definition['afterCommit'] ?? null),
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

    /** @return array<int, string> */
    private function events(string $observer, mixed $raw): array
    {
        if ($raw === null || $raw === true || $raw === '' || $raw === []) {
            return array_values((array) config('blueclues.default_events', ['creating', 'updating', 'deleted']));
        }

        $events = is_array($raw) ? $raw : explode(',', (string) $raw);
        $events = array_values(array_unique(array_filter(array_map(
            static fn (mixed $event): string => trim((string) $event),
            $events,
        ), static fn (string $event): bool => $event !== '')));

        $unknown = array_diff($events, self::VALID_EVENTS);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Observer [%s] has unknown event(s): %s. Valid events: %s.',
                $observer,
                implode(', ', $unknown),
                implode(', ', self::VALID_EVENTS),
            ));
        }

        return $events;
    }

    private function afterCommit(mixed $raw): bool
    {
        if ($raw === null) {
            return (bool) config('blueclues.after_commit', true);
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? (bool) config('blueclues.after_commit', true);
    }
}
