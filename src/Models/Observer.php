<?php

declare(strict_types=1);

namespace Dakotajune\Blueclues\Models;

use Blueprint\Contracts\Model as BlueprintModel;

/**
 * Tree representation of one observer from the draft's `observers:` block.
 */
final class Observer implements BlueprintModel
{
    /** @param  array<int, string>  $events */
    public function __construct(
        private readonly string $name,
        private readonly string $modelName,
        private readonly array $events,
        private readonly bool $afterCommit,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    /** Model reference as written in the draft (may include a sub-namespace, e.g. Admin\User). */
    public function modelName(): string
    {
        return $this->modelName;
    }

    /** @return array<int, string> */
    public function events(): array
    {
        return $this->events;
    }

    public function afterCommit(): bool
    {
        return $this->afterCommit;
    }

    /** Namespace relative to the application root namespace, e.g. "Observers". */
    public function namespace(): string
    {
        return trim((string) config('blueclues.namespace', 'Observers'), '\\');
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
