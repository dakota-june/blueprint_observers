<?php

declare(strict_types=1);

use Blueprint\Blueprint;
use Dakotajune\BlueprintObservers\Snippets\SnippetRegistry;
use Dakotajune\BlueprintObservers\Tests\Support\AuditSnippet;
use Dakotajune\BlueprintObservers\Tests\Support\BrokenSnippet;

beforeEach(function () {
    $registry = app(SnippetRegistry::class);
    $registry->register(new AuditSnippet());
    $registry->register(new BrokenSnippet());
});

function build(string $yaml, array $only = [], array $skip = []): array
{
    $blueprint = app(Blueprint::class);

    return $blueprint->generate($blueprint->analyze($blueprint->parse($yaml)), $only, $skip);
}

const DRAFT = <<<'YAML'
models:
  Post:
    title: string
observers:
  Post:
    events:
      creating: [audit]
      updated:
YAML;

it('creates the observer and attaches it to an existing model', function () {
    $this->writeModel('Post');

    $generated = build(DRAFT, only: ['observers']);

    expect(generatedPaths($generated['created']))->toContain('app/Observers/PostObserver.php')
        ->and(generatedPaths($generated['updated']))->toContain('app/Models/Post.php');

    $observer = file_get_contents("{$this->project}/app/Observers/PostObserver.php");
    expect($observer)
        ->toContain('class PostObserver implements ShouldHandleEventsAfterCommit')
        ->toContain('@generated blueprint_observers')
        ->toContain("Log::info('creating'")
        ->toContain('use Illuminate\\Support\\Facades\\Log;')
        ->toContain('public function updated(Post $post): void');

    expect(file_get_contents("{$this->project}/app/Models/Post.php"))
        ->toContain('#[ObservedBy(PostObserver::class)]')
        ->and(lintSource($observer))->toBeTrue();
});

it('keeps the observers block out of models and columns', function () {
    $blueprint = app(Blueprint::class);
    $tree = $blueprint->analyze($blueprint->parse(DRAFT));

    expect(array_keys($tree->models()))->toBe(['Post'])
        ->and(array_keys($tree->toArray()['observers']))->toBe(['PostObserver']);
});

it('respects --skip=observers', function () {
    $this->writeModel('Post');

    build(DRAFT, skip: ['observers']);

    expect(file_exists("{$this->project}/app/Observers/PostObserver.php"))->toBeFalse();
});

it('fails the build, naming the snippet, when a snippet emits invalid PHP', function () {
    $this->writeModel('Post');

    build("observers:\n  Post:\n    events:\n      creating: [broken]\n", only: ['observers']);
})->throws(\Dakotajune\BlueprintObservers\Snippets\SnippetException::class, '[broken]');

it('rebuilds without touching developer code and honours the draft', function () {
    $this->writeModel('Post');
    build(DRAFT, only: ['observers']);

    $path = "{$this->project}/app/Observers/PostObserver.php";
    $developer = "    public function saving(Post \$post): void\n    {\n        \$post->touch();\n    }";
    file_put_contents($path, preg_replace('/\n}\n$/', "\n\n{$developer}\n}\n", file_get_contents($path)));

    $generated = build("observers:\n  Post:\n    events:\n      creating: [audit]\n      deleted:\n", only: ['observers']);
    $rebuilt = file_get_contents($path);

    expect(generatedPaths($generated['created']))->toContain('app/Observers/PostObserver.php')
        ->and($rebuilt)->toContain($developer)
        ->not->toContain('function updated')
        ->toContain('function deleted')
        ->and(lintSource($rebuilt))->toBeTrue();
});

it('blueprint:erase deletes an observer that holds no developer code and reverts the model', function () {
    $this->writeModel('Post');
    $original = file_get_contents("{$this->project}/app/Models/Post.php");

    $blueprint = app(Blueprint::class);
    file_put_contents("{$this->project}/.blueprint", $blueprint->dump(recordedLikeBlueprint(build(DRAFT, only: ['observers']))));

    expect($this->eraseLikeTheCli())->toBe(0);

    expect(file_exists("{$this->project}/app/Observers/PostObserver.php"))->toBeFalse()
        ->and(file_get_contents("{$this->project}/app/Models/Post.php"))->toBe($original)
        ->and(glob("{$this->project}/.blueprint.bak-*"))->toBe([])
        ->and(file_exists("{$this->project}/.blueprint_observers"))->toBeFalse();
});

it('blueprint:erase keeps an observer that contains developer code, backs up .blueprint, strips generated methods', function () {
    $this->writeModel('Post');
    $blueprint = app(Blueprint::class);
    $recorded = $blueprint->dump(recordedLikeBlueprint(build(DRAFT, only: ['observers'])));
    file_put_contents("{$this->project}/.blueprint", $recorded);

    $path = "{$this->project}/app/Observers/PostObserver.php";
    $developer = "    public function saving(Post \$post): void\n    {\n        \$post->touch();\n    }";
    file_put_contents($path, preg_replace('/\n}\n$/', "\n\n{$developer}\n}\n", file_get_contents($path)));

    expect($this->eraseLikeTheCli())->toBe(0);

    $kept = file_get_contents($path);
    expect(file_exists($path))->toBeTrue()
        ->and($kept)->toContain($developer)->not->toContain('@generated')
        ->and(glob("{$this->project}/.blueprint.bak-*"))->toHaveCount(1)
        ->and(file_get_contents(glob("{$this->project}/.blueprint.bak-*")[0]))->toBe($recorded);
});

it('reports every generated file as a [label, path] pair, the shape blueprint:build prints and records', function () {
    $this->writeModel('Post');

    $generated = build(DRAFT, only: ['observers']);

    expect($generated['created'])->toContain(['Observer', 'app/Observers/PostObserver.php'])
        ->and($generated['updated'])->toContain(['Model', 'app/Models/Post.php']);

    foreach ($generated as $entries) {
        foreach ($entries as $entry) {
            expect($entry)->toHaveCount(2)->and($entry[0])->toBeString()->and($entry[1])->toBeString();
        }
    }
});
