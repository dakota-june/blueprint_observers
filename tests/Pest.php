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
