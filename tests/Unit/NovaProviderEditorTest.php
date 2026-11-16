<?php

declare(strict_types=1);

use Dakotajune\BlueprintObservers\Support\NovaProviderEditor;

const NOVA_REGISTRATIONS = [['model' => 'App\\Models\\Post', 'observer' => 'App\\Nova\\Observers\\PostObserver']];

function novaProviderSource(?string $boot = null): string
{
    $boot ??= "    /**\n     * Bootstrap any application services.\n     */\n    public function boot(): void\n    {\n        parent::boot();\n    }\n";

    return "<?php\n\nnamespace App\\Providers;\n\nuse Illuminate\\Support\\Facades\\Gate;\nuse Laravel\\Nova\\Nova;\nuse Laravel\\Nova\\NovaApplicationServiceProvider;\n\nclass NovaServiceProvider extends NovaApplicationServiceProvider\n{\n"
        . $boot
        . "\n    /**\n     * Register the Nova gate.\n     */\n    protected function gate(): void\n    {\n        Gate::define('viewNova', function (\$user) {\n            return in_array(\$user->email, [\n                //\n            ]);\n        });\n    }\n}\n";
}

it('adds blueprintObservers(), its imports and one call at the end of boot()', function () {
    $result = (new NovaProviderEditor())->update(novaProviderSource(), 'NovaServiceProvider', NOVA_REGISTRATIONS);

    expect($result->changed)->toBeTrue()
        ->and($result->source)
        ->toContain('use Laravel\\Nova\\Observable;')
        ->toContain('use App\\Models\\Post;')
        ->toContain('use App\\Nova\\Observers\\PostObserver;')
        ->toContain('        Observable::make(Post::class, PostObserver::class);')
        ->toContain("        parent::boot();\n\n        \$this->blueprintObservers();\n    }")
        ->and(substr_count($result->source, '@generated blueprint_observers'))->toBe(1)
        ->and(substr_count($result->source, '$this->blueprintObservers();'))->toBe(1)
        ->and(lintSource($result->source))->toBeTrue();
});

it('never touches the rest of the provider', function () {
    $result = (new NovaProviderEditor())->update(novaProviderSource(), 'NovaServiceProvider', NOVA_REGISTRATIONS);

    expect($result->source)->toContain("Gate::define('viewNova', function (\$user) {\n            return in_array(\$user->email, [\n                //\n            ]);\n        });")
        ->toContain('use Illuminate\\Support\\Facades\\Gate;')
        ->toContain('use Laravel\\Nova\\NovaApplicationServiceProvider;');
});

it('is idempotent', function () {
    $editor = new NovaProviderEditor();
    $first = $editor->update(novaProviderSource(), 'NovaServiceProvider', NOVA_REGISTRATIONS);
    $second = $editor->update($first->source, 'NovaServiceProvider', NOVA_REGISTRATIONS);

    expect($second->changed)->toBeFalse()->and($second->source)->toBe($first->source)->and($second->addedImports)->toBe([]);
});

it('rewrites the tagged method in place when the observers change', function () {
    $editor = new NovaProviderEditor();
    $first = $editor->update(novaProviderSource(), 'NovaServiceProvider', NOVA_REGISTRATIONS);
    $two = [...NOVA_REGISTRATIONS, ['model' => 'App\\Models\\Invoice', 'observer' => 'App\\Nova\\Observers\\InvoiceObserver']];

    $grown = $editor->update($first->source, 'NovaServiceProvider', $two);

    expect($grown->source)->toContain('Observable::make(Invoice::class, InvoiceObserver::class);')
        ->and(substr_count($grown->source, 'function blueprintObservers'))->toBe(1)
        ->and(substr_count($grown->source, '$this->blueprintObservers();'))->toBe(1)
        ->and(lintSource($grown->source))->toBeTrue();

    $shrunk = $editor->update($grown->source, 'NovaServiceProvider', NOVA_REGISTRATIONS);
    expect($shrunk->source)->not->toContain('InvoiceObserver::class);');
});

it('leaves an untagged blueprintObservers() alone, says so, and still makes sure it is called', function () {
    $mine = novaProviderSource("    public function boot(): void\n    {\n        parent::boot();\n    }\n\n    protected function blueprintObservers(): void\n    {\n        // mine\n    }\n");

    $result = (new NovaProviderEditor())->update($mine, 'NovaServiceProvider', NOVA_REGISTRATIONS);

    expect($result->notices)->toHaveCount(1)
        ->and($result->source)->toContain("protected function blueprintObservers(): void\n    {\n        // mine\n    }")
        ->not->toContain('@generated')->not->toContain('Observable')
        ->toContain('$this->blueprintObservers();');
});

it('does not add a second call, and does not count a commented-out one', function () {
    $editor = new NovaProviderEditor();

    $called = novaProviderSource("    public function boot(): void\n    {\n        parent::boot();\n\n        \$this->blueprintObservers();\n    }\n");
    expect(substr_count($editor->update($called, 'NovaServiceProvider', NOVA_REGISTRATIONS)->source, '$this->blueprintObservers();'))->toBe(1);

    $commented = novaProviderSource("    public function boot(): void\n    {\n        parent::boot();\n        // \$this->blueprintObservers();\n    }\n");
    $result = $editor->update($commented, 'NovaServiceProvider', NOVA_REGISTRATIONS);
    expect(substr_count($result->source, '$this->blueprintObservers();'))->toBe(2)->and(lintSource($result->source))->toBeTrue();
});

it('adds boot() when the provider has none, calling parent::boot()', function () {
    $source = "<?php\n\nnamespace App\\Providers;\n\nuse Laravel\\Nova\\NovaApplicationServiceProvider;\n\nclass NovaServiceProvider extends NovaApplicationServiceProvider\n{\n    protected function gate(): void\n    {\n    }\n}\n";

    $result = (new NovaProviderEditor())->update($source, 'NovaServiceProvider', NOVA_REGISTRATIONS);

    expect($result->source)->toContain("public function boot(): void\n    {\n        parent::boot();\n\n        \$this->blueprintObservers();\n    }")
        ->and(lintSource($result->source))->toBeTrue();
});

it('falls back to a fully qualified name when a short name is already taken', function () {
    $source = str_replace('use Laravel\\Nova\\Nova;', "use App\\Nova\\Post;\nuse Laravel\\Nova\\Nova;", novaProviderSource());

    $result = (new NovaProviderEditor())->update($source, 'NovaServiceProvider', NOVA_REGISTRATIONS);

    expect($result->source)->toContain('\\App\\Models\\Post::class')->not->toContain('use App\\Models\\Post;')
        ->and(lintSource($result->source))->toBeTrue();
});

it('strip() puts the file back exactly as it was and keeps imports the developer uses', function () {
    $editor = new NovaProviderEditor();
    $original = novaProviderSource();

    $built = $editor->update($original, 'NovaServiceProvider', NOVA_REGISTRATIONS);
    expect($editor->strip($built->source, 'NovaServiceProvider', $built->addedImports))->toBe($original);

    $usesPost = str_replace('use Laravel\\Nova\\Nova;', "use App\\Models\\Post;\nuse Laravel\\Nova\\Nova;", str_replace(
        '    protected function gate(): void',
        "    protected function other(): string\n    {\n        return Post::class;\n    }\n\n    protected function gate(): void",
        $original,
    ));
    $built = $editor->update($usesPost, 'NovaServiceProvider', NOVA_REGISTRATIONS);
    expect($editor->strip($built->source, 'NovaServiceProvider', $built->addedImports))->toBe($usesPost);
});

it('strip() leaves a developer-owned blueprintObservers() and an untouched provider alone', function () {
    $editor = new NovaProviderEditor();
    $mine = novaProviderSource("    public function boot(): void\n    {\n        parent::boot();\n        \$this->blueprintObservers();\n    }\n\n    protected function blueprintObservers(): void\n    {\n    }\n");

    expect($editor->strip($mine, 'NovaServiceProvider'))->toBe($mine)
        ->and($editor->strip(novaProviderSource(), 'NovaServiceProvider'))->toBe(novaProviderSource());
});

it('copes with one-line and empty boot() bodies', function () {
    $editor = new NovaProviderEditor();

    $oneLine = $editor->update(novaProviderSource("    public function boot(): void { parent::boot(); }\n"), 'NovaServiceProvider', NOVA_REGISTRATIONS);
    expect(lintSource($oneLine->source))->toBeTrue()->and($oneLine->source)->toContain('$this->blueprintObservers();');

    $empty = novaProviderSource("    public function boot(): void\n    {\n    }\n");
    $built = $editor->update($empty, 'NovaServiceProvider', NOVA_REGISTRATIONS);
    expect($built->source)->toContain("{\n        \$this->blueprintObservers();\n    }")
        ->and($editor->strip($built->source, 'NovaServiceProvider', $built->addedImports))->toBe($empty);
});

it('reports a provider class it cannot find', function () {
    $result = (new NovaProviderEditor())->update(novaProviderSource(), 'Nope', NOVA_REGISTRATIONS);

    expect($result->changed)->toBeFalse()->and($result->notices)->toHaveCount(1);
});
