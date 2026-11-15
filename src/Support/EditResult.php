<?php

declare(strict_types=1);

namespace Dakotajune\Blueclues\Support;

final class EditResult
{
    /** @param  array<int, string>  $notices  Things the developer should know (e.g. a method was left alone). */
    public function __construct(
        public readonly string $source,
        public readonly bool $changed,
        public readonly array $notices = [],
    ) {}
}
