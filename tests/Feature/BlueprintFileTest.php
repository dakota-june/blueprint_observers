<?php

declare(strict_types=1);

use Blueprint\Blueprint;
use Dakotajune\BlueprintObservers\Support\BlueprintFile;

it('removes only our path from created, leaving Blueprint\'s [label, path] entries alone', function () {
    $blueprint = app(Blueprint::class);
    $recorded = $blueprint->dump([
        'created' => [['Model', 'app/Models/Post.php'], 'app/Observers/PostObserver.php'],
        'updated' => ['app/Models/Post.php'],
    ]);
    file_put_contents("{$this->project}/.blueprint", $recorded);

    $backup = app(BlueprintFile::class)->protect(['app/Observers/PostObserver.php']);

    $after = $blueprint->parse(file_get_contents("{$this->project}/.blueprint"), false);

    expect($backup)->not->toBeNull()
        ->and(file_get_contents($backup))->toBe($recorded)
        ->and($after['created'])->toHaveCount(1)
        ->and(json_encode($after['created'], JSON_UNESCAPED_SLASHES))->toContain('app/Models/Post.php')->not->toContain('PostObserver')
        ->and($after['updated'])->toBe(['app/Models/Post.php']);
});

it('does nothing, and makes no backup, when the path is not listed', function () {
    file_put_contents("{$this->project}/.blueprint", app(Blueprint::class)->dump(['created' => ['app/Other.php']]));

    expect(app(BlueprintFile::class)->protect(['app/Observers/PostObserver.php']))->toBeNull()
        ->and(glob("{$this->project}/.blueprint.bak-*"))->toBe([]);
});