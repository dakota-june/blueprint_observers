<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Support;

/** A guard with everything decided, ready to render. */
final class ResolvedGuard
{
    /**
     * @param  string  $name  Relationship method on the model, e.g. "payments".
     * @param  string  $action  restrict|cascade|nullify
     * @param  bool  $includeTrashed  Soft-deleted related rows count (restrict) / are included (cascade, nullify).
     * @param  bool|null  $parentSoft  Does the guarded model use soft deletes? null = not known from the draft.
     * @param  bool|null  $childSoft  Does the related model use soft deletes? null = not known from the draft.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $action,
        public readonly bool $includeTrashed,
        public readonly ?bool $parentSoft,
        public readonly ?bool $childSoft,
    ) {}
}
