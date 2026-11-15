<?php

declare(strict_types=1);

use Dakotajune\Blueclues\Support\Manifest;

it('round trips through json', function () {
    $manifest = new Manifest();
    $manifest->record('app/Models/Post.php', 'App\\Observers\\PostObserver', 'Post', ['App\\Observers\\PostObserver']);

    $restored = Manifest::fromJson($manifest->toJson());

    expect($restored->entries())->toBe($manifest->entries())
        ->and($restored->get('app/Models/Post.php', 'App\\Observers\\PostObserver'))->toBe([
            'model' => 'Post',
            'imports' => ['App\\Observers\\PostObserver'],
        ]);
});

it('tolerates empty or corrupt input', function () {
    expect(Manifest::fromJson(null)->isEmpty())->toBeTrue()
        ->and(Manifest::fromJson('not json')->isEmpty())->toBeTrue();
});
