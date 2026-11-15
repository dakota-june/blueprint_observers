<?php

declare(strict_types=1);

use Dakotajune\Blueclues\Support\ObserverClassEditor;
use Dakotajune\Blueclues\Support\ObserverMethodBuilder;

const EDITOR_MODEL = 'App\\Models\\Post';

function editorStub(): string
{
    return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Observers;\n\nclass PostObserver\n{\n}\n";
}

function generatedMethod(string $event, string $body = ''): string
{
    return ObserverMethodBuilder::build($event, 'Post', 'post', $body);
}

const DEVELOPER_METHOD = <<<'PHP'
    /** Hand written, do not touch. */
    public function saving(Post $post): void
    {
        if ($post->dirty) {


            $post->touch();   // odd   spacing   on purpose
        }
    }
PHP;

function withDeveloperCode(string $source): string
{
    $source = preg_replace('/\n}\n$/', "\n\n" . DEVELOPER_METHOD . "\n}\n", $source);

    return str_replace("\n{\n", "\n{\n    private array \$cache = [];\n\n", $source);
}

it('creates a complete observer: imports, header interface, tagged methods', function () {
    $result = (new ObserverClassEditor())->update(
        editorStub(), 'PostObserver', EDITOR_MODEL, true,
        ['creating' => generatedMethod('creating', '$post->slug = Str::slug($post->title);'), 'updated' => generatedMethod('updated')],
        ['Illuminate\\Support\\Str'],
    );

    expect($result->source)
        ->toContain('use App\\Models\\Post;')
        ->toContain('use Illuminate\\Contracts\\Events\\ShouldHandleEventsAfterCommit;')
        ->toContain('use Illuminate\\Support\\Str;')
        ->toContain('class PostObserver implements ShouldHandleEventsAfterCommit')
        ->toContain('public function creating(Post $post): void')
        ->toContain('        $post->slug = Str::slug($post->title);');

    expect(substr_count($result->source, '@generated blueclues'))->toBe(2)
        ->and(lintSource($result->source))->toBeTrue();
});

it('is idempotent', function () {
    $editor = new ObserverClassEditor();
    $methods = ['creating' => generatedMethod('creating')];

    $first = $editor->update(editorStub(), 'PostObserver', EDITOR_MODEL, true, $methods);
    $second = $editor->update($first->source, 'PostObserver', EDITOR_MODEL, true, $methods);

    expect($second->changed)->toBeFalse()->and($second->source)->toBe($first->source);
});

it('never modifies developer members when regenerating', function () {
    $editor = new ObserverClassEditor();
    $built = $editor->update(editorStub(), 'PostObserver', EDITOR_MODEL, true, ['creating' => generatedMethod('creating', '$a = 1;')])->source;
    $source = withDeveloperCode($built);

    $result = $editor->update($source, 'PostObserver', EDITOR_MODEL, true, ['creating' => generatedMethod('creating', '$a = 2;')]);

    expect($result->source)
        ->toContain('$a = 2;')->not->toContain('$a = 1;')
        ->toContain(DEVELOPER_METHOD)
        ->toContain('private array $cache = [];')
        ->and(substr_count($result->source, 'function creating'))->toBe(1)
        ->and(lintSource($result->source))->toBeTrue();
});

it('deletes only generated methods whose event left the draft, without double blank lines', function () {
    $editor = new ObserverClassEditor();
    $built = $editor->update(editorStub(), 'PostObserver', EDITOR_MODEL, true, ['creating' => generatedMethod('creating'), 'updated' => generatedMethod('updated')])->source;
    $source = withDeveloperCode($built);

    $result = $editor->update($source, 'PostObserver', EDITOR_MODEL, true, ['updated' => generatedMethod('updated')]);

    expect($result->source)
        ->not->toContain('function creating')
        ->toContain(DEVELOPER_METHOD)
        ->and($result->source)->not->toMatch('/\}\n\n\n/')
        ->and(lintSource($result->source))->toBeTrue();

    $empty = $editor->update($built, 'PostObserver', EDITOR_MODEL, true, []);
    expect($empty->source)->not->toContain('function ')->not->toMatch('/\{\n\n/')
        ->and(lintSource($empty->source))->toBeTrue();
});

it('treats an untagged method with an event name as the developer\'s and says so', function () {
    $editor = new ObserverClassEditor();
    $built = $editor->update(editorStub(), 'PostObserver', EDITOR_MODEL, true, ['creating' => generatedMethod('creating', '$a = 1;')])->source;
    $adopted = str_replace(' @generated blueclues Rewritten on every build. Remove this tag to take ownership of the method.', ' mine now', $built);

    $result = $editor->update($adopted, 'PostObserver', EDITOR_MODEL, true, ['creating' => generatedMethod('creating', '$a = 2;')]);

    expect($result->source)->toContain('$a = 1;')->not->toContain('$a = 2;')
        ->and(substr_count($result->source, 'function creating'))->toBe(1)
        ->and($result->notices)->toHaveCount(1);
});

it('manages only its own interface in the class header', function () {
    $editor = new ObserverClassEditor();
    $header = "<?php\n\nnamespace App\\Observers;\n\nuse App\\Models\\Post;\nuse Illuminate\\Contracts\\Events\\ShouldHandleEventsAfterCommit;\n\nclass PostObserver extends Base implements A, ShouldHandleEventsAfterCommit, \\Other\\B\n{\n}\n";

    $off = $editor->update($header, 'PostObserver', EDITOR_MODEL, false, []);
    expect($off->source)->toContain('class PostObserver extends Base implements A, \\Other\\B')
        ->and(lintSource($off->source))->toBeTrue();

    $on = $editor->update("<?php\n\nnamespace App\\Observers;\n\nuse App\\Models\\Post;\n\nclass PostObserver implements A\n{\n}\n", 'PostObserver', EDITOR_MODEL, true, []);
    expect($on->source)->toContain('implements A, ShouldHandleEventsAfterCommit')->and(lintSource($on->source))->toBeTrue();
});

it('only adds imports and warns when a snippet import clashes with a developer import', function () {
    $editor = new ObserverClassEditor();
    $base = $editor->update(editorStub(), 'PostObserver', EDITOR_MODEL, true, ['creating' => generatedMethod('creating', 'Str::slug("x");')])->source;
    $source = str_replace('use App\\Models\\Post;', "use App\\Models\\Post;\nuse Some\\Thing\\Str;", $base);

    $result = $editor->update($source, 'PostObserver', EDITOR_MODEL, true, ['creating' => generatedMethod('creating', 'Str::slug("x");')], ['Illuminate\\Support\\Str']);

    expect($result->source)->toContain('use Some\\Thing\\Str;')->not->toContain('use Illuminate\\Support\\Str;')
        ->and($result->notices)->toHaveCount(1);
});

it('strips generated methods and reports whether developer content remains', function () {
    $editor = new ObserverClassEditor();
    $built = $editor->update(editorStub(), 'PostObserver', EDITOR_MODEL, true, ['creating' => generatedMethod('creating')])->source;
    $withDeveloper = withDeveloperCode($built);

    expect($editor->hasDeveloperContent($built, 'PostObserver'))->toBeFalse()
        ->and($editor->hasDeveloperContent($withDeveloper, 'PostObserver'))->toBeTrue();

    $stripped = $editor->stripGenerated($withDeveloper, 'PostObserver');
    expect($stripped)->toContain(DEVELOPER_METHOD)->not->toContain('@generated')
        ->and(lintSource($stripped))->toBeTrue();
});

it('ignores closures inside developer methods and leaves a missing class alone', function () {
    $editor = new ObserverClassEditor();
    $source = "<?php\n\nnamespace App\\Observers;\n\nuse App\\Models\\Post;\n\nclass PostObserver\n{\n    public function saving(Post \$post): void\n    {\n        \$f = function (\$x) { return \$x; };\n        \$g = fn (\$y) => \$y;\n    }\n}\n";

    $result = $editor->update($source, 'PostObserver', EDITOR_MODEL, false, ['creating' => generatedMethod('creating')]);
    expect($result->source)->toContain('$f = function ($x) { return $x; };')
        ->and(substr_count($result->source, 'function creating'))->toBe(1);

    $missing = $editor->update("<?php\n\nnamespace App\\Observers;\n\nclass Other {}\n", 'PostObserver', EDITOR_MODEL, true, []);
    expect($missing->changed)->toBeFalse()->and($missing->notices)->toHaveCount(1);
});
