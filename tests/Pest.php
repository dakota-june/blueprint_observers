<?php

declare(strict_types=1);

use Dakotajune\BlueprintObservers\Tests\TestCase;

uses(TestCase::class)->in('Feature');

function lintSource(string $source): bool
{
    $file = tempnam(sys_get_temp_dir(), 'lint');
    file_put_contents($file, $source);
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
    unlink($file);

    return $code === 0;
}

/** Blueprint generators return [label, path] pairs; tests mostly care about the paths. */
function generatedPaths(array $entries): array
{
    return array_map(static fn (array $entry): string => $entry[1], $entries);
}

/** What Blueprint's builder writes to `.blueprint`: the paths only, grouped by action. */
function recordedLikeBlueprint(array $generated): array
{
    return array_map('generatedPaths', $generated);
}
