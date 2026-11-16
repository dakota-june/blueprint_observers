<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Lexers;

use Blueprint\Contracts\Lexer;
use Dakotajune\BlueprintObservers\Models\NovaObserver;
use InvalidArgumentException;

/**
 * Reads the top-level `nova_observers:` section. It is the `observers:` block (same model / afterCommit /
 * events rules, parsed by ObserverLexer) plus one extra key:
 *
 *   nova_observers:
 *     Post: ~                     # App\Nova\Observers\PostObserver, default events, no parent
 *     PaymentObserver:
 *       model: Payment
 *       extends: ~                # extend the regular App\Observers\PaymentObserver
 *       events:
 *         creating: [audit]
 *     Invoice:
 *       extends: App\Support\BaseObserver   # extend any class
 *
 * Unknown keys fail the build: a typo such as `extend:` would otherwise be dropped silently.
 */
final class NovaObserverLexer implements Lexer
{
    private const KEYS = ['model', 'afterCommit', 'events', 'guards', 'validation', 'extends'];

    public function __construct(private readonly ObserverLexer $observers) {}

    /** @return array{nova_observers?: array<string, NovaObserver>} */
    public function analyze(array $tokens): array
    {
        $section = $tokens['nova_observers'] ?? null;

        if (! is_array($section) || $section === []) {
            return [];
        }

        foreach ($section as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $unknown = array_values(array_diff(array_map('strval', array_keys($definition)), self::KEYS));

            if ($unknown !== []) {
                throw new InvalidArgumentException(sprintf(
                    'Nova observer [%s] has unknown key [%s]. Valid keys: %s.',
                    $key,
                    implode(', ', $unknown),
                    implode(', ', self::KEYS),
                ));
            }
        }

        // The shared lexer validates names, model, events, snippets and afterCommit, and keeps the order.
        $parsed = array_values($this->observers->analyze(['observers' => $section])['observers'] ?? []);
        $definitions = array_values($section);

        $nova = [];

        foreach ($parsed as $i => $observer) {
            [$kind, $class] = $this->parent($observer->name(), $definitions[$i]);

            $nova[$observer->name()] = new NovaObserver($observer, $kind, $class);
        }

        return ['nova_observers' => $nova];
    }

    /** @return array{0: string, 1: string|null} */
    private function parent(string $observer, mixed $definition): array
    {
        if (! is_array($definition) || ! array_key_exists('extends', $definition)) {
            return [NovaObserver::EXTENDS_NONE, null];
        }

        $value = $definition['extends'];

        if ($value === null || $value === true) {
            return [NovaObserver::EXTENDS_REGULAR, null];
        }

        if ($value === false) {
            return [NovaObserver::EXTENDS_NONE, null];
        }

        if (is_string($value)) {
            $class = trim(trim($value), '\\');

            if (preg_match('/^[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*$/', $class)) {
                return [NovaObserver::EXTENDS_CLASS, $class];
            }
        }

        throw new InvalidArgumentException(
            "Nova observer [{$observer}] has an invalid extends value. Use `extends: ~` for the regular observer, "
            . 'or a fully qualified class name such as `extends: App\\Support\\BaseObserver`.',
        );
    }
}
