<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Support;

final class InjectionResult
{
    public const INSERTED = 'inserted';
    public const MERGED = 'merged';
    public const UNCHANGED = 'unchanged';
    public const CLASS_NOT_FOUND = 'class-not-found';

    /**
     * @param  array<int, string>  $addedImports  Fully qualified names of `use` statements this call added.
     */
    public function __construct(
        public readonly string $source,
        public readonly string $status,
        public readonly array $addedImports = [],
    ) {}

    public function changed(): bool
    {
        return in_array($this->status, [self::INSERTED, self::MERGED], true);
    }
}
