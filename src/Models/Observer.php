<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Models;

use Blueprint\Contracts\Model as BlueprintModel;

/**
 * Tree representation of one observer from the draft's `observers:` block.
 */
final class Observer implements BlueprintModel
{
    /**
     * @param  array<string, array<int, string>>  $events  event => names of the snippets opted into for it
     * @param  array<string, Guard|null>  $guards  relationship => what to do on delete; null switches an auto-derived guard off
     * @param  bool  $autoGuards  Derive guards from the model's hasMany/hasOne relationships (`guards: auto`).
     * @param  ValidationSpec|null  $validation  The `validation:` key; null when the draft does not have it (config decides).
     */
    public function __construct(
        private readonly string $name,
        private readonly string $modelName,
        private readonly array $events,
        private readonly bool $afterCommit,
        private readonly array $guards = [],
        private readonly bool $autoGuards = false,
        private readonly ?ValidationSpec $validation = null,
    ) {}

    public function validation(): ?ValidationSpec
    {
        return $this->validation;
    }

    /** Does `blueprint_observers.validation.enabled` switch validation on for this observer? */
    public function inheritsConfigValidation(): bool
    {
        return true;
    }

    /** @return array<string, Guard|null> */
    public function guards(): array
    {
        return $this->guards;
    }

    public function autoGuards(): bool
    {
        return $this->autoGuards;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Model reference as written in the draft (may include a sub-namespace, e.g. Admin\User). */
    public function modelName(): string
    {
        return $this->modelName;
    }

    /** @return array<string, array<int, string>> */
    public function events(): array
    {
        return $this->events;
    }

    /** @return array<int, string> */
    public function eventNames(): array
    {
        return array_keys($this->events);
    }

    /** @return array<int, string> */
    public function snippets(string $event): array
    {
        return $this->events[$event] ?? [];
    }

    public function afterCommit(): bool
    {
        return $this->afterCommit;
    }

    /** Namespace relative to the application root namespace, e.g. "Observers". */
    public function namespace(): string
    {
        return trim((string) config('blueprint_observers.namespace', 'Observers'), '\\');
    }

    /** e.g. "App\Observers" */
    public function fullyQualifiedNamespace(): string
    {
        return trim((string) config('blueprint.namespace', 'App') . '\\' . $this->namespace(), '\\');
    }

    /** e.g. "App\Observers\PostObserver" */
    public function fullyQualifiedClassName(): string
    {
        return $this->fullyQualifiedNamespace() . '\\' . $this->name();
    }
}
