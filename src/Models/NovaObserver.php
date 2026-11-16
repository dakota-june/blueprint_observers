<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Models;

use Blueprint\Contracts\Model as BlueprintModel;

/**
 * Tree representation of one observer from the draft's `nova_observers:` block.
 *
 * It wraps the regular Observer the shared lexer produced (name, model, events, afterCommit) and adds what
 * is specific to Nova: its own namespace and an optional parent class.
 *
 *   extends omitted / false -> no parent
 *   extends: ~  (or true)   -> the regular observer with the same name, e.g. App\Observers\PostObserver
 *   extends: Some\Base      -> that class
 */
final class NovaObserver implements BlueprintModel
{
    public const EXTENDS_NONE = 'none';

    public const EXTENDS_REGULAR = 'regular';

    public const EXTENDS_CLASS = 'class';

    public function __construct(
        private readonly Observer $observer,
        private readonly string $extendsKind = self::EXTENDS_NONE,
        private readonly ?string $extendsClass = null,
    ) {}

    public function name(): string
    {
        return $this->observer->name();
    }

    public function modelName(): string
    {
        return $this->observer->modelName();
    }

    /** @return array<string, array<int, string>> */
    public function events(): array
    {
        return $this->observer->events();
    }

    /** @return array<int, string> */
    public function eventNames(): array
    {
        return $this->observer->eventNames();
    }

    public function afterCommit(): bool
    {
        return $this->observer->afterCommit();
    }

    public function extendsKind(): string
    {
        return $this->extendsKind;
    }

    /** FQCN of the parent class, or null when the observer does not extend anything. */
    public function parentClass(): ?string
    {
        return match ($this->extendsKind) {
            self::EXTENDS_REGULAR => $this->regularObserverClassName(),
            self::EXTENDS_CLASS => $this->extendsClass,
            default => null,
        };
    }

    /** The non-Nova observer with the same name, e.g. "App\Observers\PostObserver". */
    public function regularObserverClassName(): string
    {
        return $this->observer->fullyQualifiedClassName();
    }

    /** Namespace relative to the application root namespace, e.g. "Nova\Observers". */
    public function namespace(): string
    {
        return trim((string) config('blueprint_observers.nova_namespace', 'Nova\\Observers'), '\\');
    }

    /** e.g. "App\Nova\Observers" */
    public function fullyQualifiedNamespace(): string
    {
        return trim((string) config('blueprint.namespace', 'App') . '\\' . $this->namespace(), '\\');
    }

    /** e.g. "App\Nova\Observers\PostObserver" */
    public function fullyQualifiedClassName(): string
    {
        return $this->fullyQualifiedNamespace() . '\\' . $this->name();
    }
}
