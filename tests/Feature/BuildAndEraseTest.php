<?php

declare(strict_types=1);

use Blueprint\Blueprint;

function build(string $yaml, array $only = [], array $skip = []): array
{
    $blueprint = app(Blueprint::class);
    $tree = $blueprint->analyze($blueprint->parse($yaml));

    return $blueprint->generate($tree, $only, $skip);
}

const DRAFT = <<<'YAML'
models:
  Post:
    title: string
observers:
  Post:
    events: [creating, updated]
YAML;

it('generates the observer and attaches it to an existing model', function () {
    $this->writeModel('Post');

    $generated = build(DRAFT, only: ['observers']);

    expect($generated['created'])->toContain('app/Observers/PostObserver.php')
        ->and($generated['updated'])->toContain('app/Models/Post.php');

    $observer = file_get_contents("{$this->project}/app/Observers/PostObserver.php");
    expect($observer)
        ->toContain('class PostObserver implements ShouldHandleEventsAfterCommit')
        ->toContain('public function creating(Post $post): void')
        ->toContain('public function updated(Post $post): void')
        ->not->toContain('public function deleted');

    expect(file_get_contents("{$this->project}/app/Models/Post.php"))
        ->toContain('#[ObservedBy(PostObserver::class)]')
        ->toContain('use App\\Observers\\PostObserver;');

    expect(lintPhp("{$this->project}/app/Observers/PostObserver.php"))->toBeTrue()
        ->and(lintPhp("{$this->project}/app/Models/Post.php"))->toBeTrue();
});

it('does not treat the observers block as models or columns', function () {
    $blueprint = app(Blueprint::class);
    $tree = $blueprint->analyze($blueprint->parse(DRAFT));

    expect(array_keys($tree->models()))->toBe(['Post'])
        ->and(array_keys($tree->toArray()['observers']))->toBe(['PostObserver']);
});

it('respects --skip=observers', function () {
    $this->writeModel('Post');

    $generated = build(DRAFT, skip: ['observers']);

    expect($generated['created'] ?? [])->not->toContain('app/Observers/PostObserver.php');
    expect(file_exists("{$this->project}/app/Observers/PostObserver.php"))->toBeFalse();
});

it('is idempotent across rebuilds and keeps tracking the injection', function () {
    $this->writeModel('Post');

    build(DRAFT, only: ['observers']);
    $afterFirst = file_get_contents("{$this->project}/app/Models/Post.php");
    $second = build(DRAFT, only: ['observers']);

    expect(file_get_contents("{$this->project}/app/Models/Post.php"))->toBe($afterFirst)
        ->and($second['updated'] ?? [])->toBe([])
        ->and(file_exists("{$this->project}/.blueclues"))->toBeTrue();
});

it('blueprint:erase removes the observer and reverts the model edit', function () {
    $this->writeModel('Post');
    $original = file_get_contents("{$this->project}/app/Models/Post.php");

    $blueprint = app(Blueprint::class);
    $generated = build(DRAFT, only: ['observers']);
    file_put_contents("{$this->project}/.blueprint", $blueprint->dump($generated));

    $this->artisan('blueprint:erase')->assertSuccessful();

    expect(file_exists("{$this->project}/app/Observers/PostObserver.php"))->toBeFalse()
        ->and(file_get_contents("{$this->project}/app/Models/Post.php"))->toBe($original)
        ->and(file_exists("{$this->project}/.blueclues"))->toBeFalse();
});

function lintPhp(string $path): bool
{
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);

    return $code === 0;
}
