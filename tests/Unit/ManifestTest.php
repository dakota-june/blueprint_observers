<?php

declare(strict_types=1);

use Dakotajune\BlueprintObservers\Support\Manifest;

it('round trips models and observers through json', function () {
    $manifest = new Manifest();
    $manifest->recordModel('app/Models/Post.php', 'App\\Observers\\PostObserver', 'Post', ['App\\Observers\\PostObserver']);
    $manifest->recordObserver('app/Observers/PostObserver.php', 'PostObserver', true);

    $restored = Manifest::fromJson($manifest->toJson());

    expect($restored->models())->toBe($manifest->models())
        ->and($restored->observers())->toBe($manifest->observers())
        ->and($restored->model('app/Models/Post.php', 'App\\Observers\\PostObserver'))
        ->toBe(['model' => 'Post', 'imports' => ['App\\Observers\\PostObserver']])
        ->and($restored->observer('app/Observers/PostObserver.php'))
        ->toBe(['class' => 'PostObserver', 'created' => true]);
});

it('tolerates empty or corrupt input', function () {
    expect(Manifest::fromJson(null)->isEmpty())->toBeTrue()
        ->and(Manifest::fromJson('not json')->isEmpty())->toBeTrue();
});
