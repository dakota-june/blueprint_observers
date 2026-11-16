<?php

declare(strict_types=1);

use Blueprint\Blueprint;
use Dakotajune\BlueprintObservers\Snippets\SnippetRegistry;
use Dakotajune\BlueprintObservers\Support\NoticeBag;
use Dakotajune\BlueprintObservers\Tests\Support\AuditSnippet;

const NOVA_PROVIDER = 'app/Providers/NovaServiceProvider.php';
const NOVA_OBSERVER = 'app/Nova/Observers/PostObserver.php';

const NOVA_DRAFT = <<<'YAML'
models:
  Post:
    title: string
nova_observers:
  Post:
    events:
      creating: [audit]
      updated:
YAML;

const NOVA_AND_REGULAR_DRAFT = <<<'YAML'
models:
  Post:
    title: string
observers:
  Post: ~
nova_observers:
  Post:
    extends: ~
YAML;

function novaBuild(string $yaml, array $only = ['observers', 'nova_observers']): array
{
    $blueprint = app(Blueprint::class);

    return $blueprint->generate($blueprint->analyze($blueprint->parse($yaml)), $only);
}

function novaProviderFile(): string
{
    return "<?php\n\nnamespace App\\Providers;\n\nuse Illuminate\\Support\\Facades\\Gate;\nuse Laravel\\Nova\\NovaApplicationServiceProvider;\n\nclass NovaServiceProvider extends NovaApplicationServiceProvider\n{\n    /**\n     * Bootstrap any application services.\n     */\n    public function boot(): void\n    {\n        parent::boot();\n    }\n\n    protected function gate(): void\n    {\n        Gate::define('viewNova', fn (\$user) => true);\n    }\n}\n";
}

beforeEach(function () {
    app(SnippetRegistry::class)->register(new AuditSnippet());

    $this->original = novaProviderFile();
    file_put_contents("{$this->project}/" . NOVA_PROVIDER, $this->original);
});

it('creates the Nova observer and registers it from blueprintObservers()', function () {
    $this->writeModel('Post');

    $generated = novaBuild(NOVA_DRAFT);

    expect($generated['created'])->toContain(NOVA_OBSERVER)
        ->and($generated['updated'])->toContain(NOVA_PROVIDER);

    $observer = file_get_contents("{$this->project}/" . NOVA_OBSERVER);
    $provider = file_get_contents("{$this->project}/" . NOVA_PROVIDER);

    expect($observer)
        ->toContain('namespace App\\Nova\\Observers;')
        ->toContain('class PostObserver implements ShouldHandleEventsAfterCommit')
        ->toContain("Log::info('creating'")
        ->and($provider)
        ->toContain('Observable::make(Post::class, PostObserver::class);')
        ->toContain('$this->blueprintObservers();')
        ->toContain("Gate::define('viewNova', fn (\$user) => true);")
        ->and(lintSource($observer))->toBeTrue()
        ->and(lintSource($provider))->toBeTrue();

    // Nova observers are registered through Observable, never through #[ObservedBy].
    expect(file_get_contents("{$this->project}/app/Models/Post.php"))->not->toContain('ObservedBy');
});

it('rebuilds without changing anything', function () {
    $this->writeModel('Post');
    novaBuild(NOVA_DRAFT);

    $before = [md5_file("{$this->project}/" . NOVA_OBSERVER), md5_file("{$this->project}/" . NOVA_PROVIDER)];
    $second = novaBuild(NOVA_DRAFT);
    $after = [md5_file("{$this->project}/" . NOVA_OBSERVER), md5_file("{$this->project}/" . NOVA_PROVIDER)];

    expect($after)->toBe($before)
        ->and($second['created'])->toContain(NOVA_OBSERVER)       // still ours, so erase may delete it
        ->and($second['updated'])->not->toContain(NOVA_PROVIDER);
});

it('extends: ~ extends the regular observer of the same name', function () {
    $this->writeModel('Post');

    novaBuild(NOVA_AND_REGULAR_DRAFT);

    $observer = file_get_contents("{$this->project}/" . NOVA_OBSERVER);

    expect($observer)->toContain('class PostObserver extends \\App\\Observers\\PostObserver implements ShouldHandleEventsAfterCommit')
        ->and(lintSource($observer))->toBeTrue()
        ->and(file_exists("{$this->project}/app/Observers/PostObserver.php"))->toBeTrue();
});

it('extends: Some\\Class imports and extends that class', function () {
    $this->writeModel('Post');

    novaBuild("models:\n  Post:\n    title: string\nnova_observers:\n  Post:\n    extends: App\\Support\\BaseObserver\n", ['nova_observers']);

    $observer = file_get_contents("{$this->project}/" . NOVA_OBSERVER);

    expect($observer)->toContain('use App\\Support\\BaseObserver;')
        ->toContain('class PostObserver extends BaseObserver implements ShouldHandleEventsAfterCommit')
        ->and(lintSource($observer))->toBeTrue();
});

it('still writes the observer, with a notice, when there is no Nova provider', function () {
    $this->writeModel('Post');
    unlink("{$this->project}/" . NOVA_PROVIDER);

    $generated = novaBuild(NOVA_DRAFT);

    expect($generated['created'])->toContain(NOVA_OBSERVER)
        ->and(implode("\n", app(NoticeBag::class)->pull()))->toContain('was not found');
});

it('takes the registration out again when the nova_observers block is removed', function () {
    $this->writeModel('Post');
    novaBuild(NOVA_DRAFT);

    novaBuild("models:\n  Post:\n    title: string\n", ['nova_observers']);

    expect(file_get_contents("{$this->project}/" . NOVA_PROVIDER))->toBe($this->original)
        ->and(file_exists("{$this->project}/" . NOVA_OBSERVER))->toBeTrue();
});

it('blueprint:erase deletes the Nova and regular observers and restores the provider and model', function () {
    $this->writeModel('Post');
    $model = file_get_contents("{$this->project}/app/Models/Post.php");

    novaBuild(NOVA_AND_REGULAR_DRAFT);
    $recorded = novaBuild(NOVA_AND_REGULAR_DRAFT);      // a second build must not lose track of anything

    file_put_contents("{$this->project}/.blueprint", app(Blueprint::class)->dump($recorded));

    expect($this->eraseLikeTheCli())->toBe(0)
        ->and(file_exists("{$this->project}/" . NOVA_OBSERVER))->toBeFalse()
        ->and(file_exists("{$this->project}/app/Observers/PostObserver.php"))->toBeFalse()
        ->and(file_get_contents("{$this->project}/" . NOVA_PROVIDER))->toBe($this->original)
        ->and(file_get_contents("{$this->project}/app/Models/Post.php"))->toBe($model)
        ->and(file_exists("{$this->project}/.blueprint_observers"))->toBeFalse();
});

it('blueprint:erase keeps a Nova observer that holds developer code, but no longer registers it', function () {
    $this->writeModel('Post');
    file_put_contents("{$this->project}/.blueprint", app(Blueprint::class)->dump(novaBuild(NOVA_DRAFT)));

    $path = "{$this->project}/" . NOVA_OBSERVER;
    $developer = "    public function saving(Post \$post): void\n    {\n        \$post->touch();\n    }";
    file_put_contents($path, preg_replace('/\n}\n$/', "\n\n{$developer}\n}\n", file_get_contents($path)));

    expect($this->eraseLikeTheCli())->toBe(0);

    expect(file_get_contents($path))->toContain($developer)->not->toContain('@generated')
        ->and(file_get_contents("{$this->project}/" . NOVA_PROVIDER))->toBe($this->original);
});
