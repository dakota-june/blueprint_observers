<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Support;

final class ProviderEdit
{
    /**
     * @param  array<int, string>  $notices  Things the developer should know (e.g. a method was left alone).
     * @param  array<int, string>  $addedImports  Fully qualified names of `use` statements this edit added.
     */
    public function __construct(
        public readonly string $source,
        public readonly bool $changed,
        public readonly array $notices = [],
        public readonly array $addedImports = [],
    ) {}
}
