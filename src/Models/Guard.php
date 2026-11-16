<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Models;

/**
 * What an observer does about a relationship when its model is deleted, as written in the draft.
 *
 *   restrict  block the delete (ValidationException) while related rows exist
 *   cascade   delete the related rows
 *   nullify   set their foreign key to null
 *
 * `action` null means "not said": it is worked out from the draft (the child's `ondelete:`) or config.
 * `trashed` null means "not said": config('blueprint_observers.guards.trashed') decides.
 */
final class Guard
{
    public const RESTRICT = 'restrict';

    public const CASCADE = 'cascade';

    public const NULLIFY = 'nullify';

    public const ACTIONS = [self::RESTRICT, self::CASCADE, self::NULLIFY];

    public const TRASHED_BLOCK = 'block';

    public const TRASHED_IGNORE = 'ignore';

    public function __construct(
        public readonly ?string $action = null,
        public readonly ?string $trashed = null,
    ) {}
}
