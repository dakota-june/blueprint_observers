<?php

declare(strict_types=1);

/*
 * ONE-OFF: writes what the generator produces into <package root>\review-output\ for manual review.
 * Not part of the repo; delete this file (and the review-output folder) when you are done.
 *
 *   vendor\bin\pest tests\Feature\ReviewDumpTest.php
 *
 *   review-output\1-fresh-build\       app\, database\ exactly as a first build leaves them
 *   review-output\2-rebuild\           same project after a developer edit + a changed draft (updated -> saved)
 *   review-output\*.json / *.yaml      the draft and Blueprint's build result for each step
 */

use Blueprint\Blueprint;
use Dakotajune\BlueprintObservers\Snippets\SnippetRegistry;
use Dakotajune\BlueprintObservers\Tests\Support\AuditSnippet;
use Illuminate\Filesystem\Filesystem;

it('writes the generated code out for manual review', function () {
    $files = new Filesystem();
    $out = dirname(__DIR__, 2) . '/review-output';

    $files->deleteDirectory($out);
    $files->ensureDirectoryExists($out);

    app(SnippetRegistry::class)->register(new AuditSnippet());
    $blueprint = app(Blueprint::class);

    $snapshot = function (string $name, string $draft, array $generated) use ($files, $out): void {
        $dir = "{$out}/{$name}";
        $files->ensureDirectoryExists($dir);

        foreach (['app', 'database'] as $folder) {
            if ($files->isDirectory("{$this->project}/{$folder}")) {
                $files->copyDirectory("{$this->project}/{$folder}", "{$dir}/{$folder}");
            }
        }

        foreach (['.blueprint', '.blueprint_observers'] as $meta) {
            if ($files->exists("{$this->project}/{$meta}")) {
                $files->copy("{$this->project}/{$meta}", "{$dir}/" . ltrim($meta, '.') . '.txt');
            }
        }

        $files->put("{$out}/{$name}.draft.yaml", $draft);
        $files->put("{$out}/{$name}.result.json", json_encode($generated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    };

    // 1. Fresh build of the shipped example draft.
    $draft = file_get_contents(dirname(__DIR__, 2) . '/examples/draft.yaml');
    $tree = $blueprint->analyze($blueprint->parse($draft));
    $snapshot('1-fresh-build', $draft, $blueprint->generate($tree, ['models', 'observers']));

    // 2. Developer adds a method, then the draft drops `updated` and adds `saved`.
    $path = "{$this->project}/app/Observers/PaymentObserver.php";
    $developer = "    /** Mine. The generator must never touch this. */\n    public function saving(Payment \$payment): void\n    {\n        \$payment->touch();\n    }";
    $files->put($path, preg_replace('/\n}\n\s*$/', "\n\n{$developer}\n}\n", $files->get($path)));

    $draft2 = preg_replace('/^(\s*)updated:.*$/m', '$1saved:', $draft, 1);   // drop `updated`, add `saved`
    $draft2 = rtrim($draft2) . "\n";

    $tree2 = $blueprint->analyze($blueprint->parse($draft2));
    $snapshot('2-rebuild', $draft2, $blueprint->generate($tree2, ['models', 'observers']));

    fwrite(STDOUT, PHP_EOL . 'Review output written to: ' . realpath($out) . PHP_EOL);
    expect(is_file("{$out}/1-fresh-build/app/Observers/PaymentObserver.php"))->toBeTrue();
});