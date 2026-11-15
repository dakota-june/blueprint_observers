<?php

declare(strict_types=1);

use Dakotajune\BlueprintObservers\Support\InjectionResult;
use Dakotajune\BlueprintObservers\Support\ObservedByInjector;

const OBSERVER = 'App\\Observers\\PostObserver';
const OBSERVED_BY = 'Illuminate\\Database\\Eloquent\\Attributes\\ObservedBy';

function plainModel(): string
{
    return "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Post extends Model\n{\n}";
}

it('inserts the attribute and both imports into a plain model', function () {
    $result = (new ObservedByInjector())->inject(plainModel(), 'Post', OBSERVER);

    expect($result->status)->toBe(InjectionResult::INSERTED)
        ->and($result->source)->toContain("#[ObservedBy(PostObserver::class)]\nclass Post")
        ->and($result->addedImports)->toBe([OBSERVER, OBSERVED_BY]);
});

it('is idempotent', function () {
    $injector = new ObservedByInjector();
    $first = $injector->inject(plainModel(), 'Post', OBSERVER);
    $second = $injector->inject($first->source, 'Post', OBSERVER);

    expect($second->status)->toBe(InjectionResult::UNCHANGED)->and($second->source)->toBe($first->source);
});

it('restores the original file on remove()', function () {
    $injector = new ObservedByInjector();
    $result = $injector->inject(plainModel(), 'Post', OBSERVER);

    expect($injector->remove($result->source, 'Post', OBSERVER, $result->addedImports))->toBe(plainModel());
});

it('merges into an existing, aliased #[ObservedBy] instead of adding a second one', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Models;

    use App\Observers\AuditObserver;
    use Illuminate\Database\Eloquent\Attributes\ObservedBy as Watch;
    use Illuminate\Database\Eloquent\Model;

    /** A post. */
    #[\AllowDynamicProperties]
    #[Watch(AuditObserver::class)]
    final class Post extends Model
    {
    }
    PHP;

    $injector = new ObservedByInjector();
    $result = $injector->inject($source, 'Post', OBSERVER);

    expect($result->status)->toBe(InjectionResult::MERGED)
        ->and($result->source)->toContain('#[Watch([AuditObserver::class, PostObserver::class])]')
        ->and($result->source)->toContain('#[\AllowDynamicProperties]')
        ->and($result->addedImports)->toBe([OBSERVER])
        ->and($injector->remove($result->source, 'Post', OBSERVER, $result->addedImports))->toBe($source);
});

it('falls back to a fully qualified reference when the short name is taken', function () {
    $source = str_replace(
        'use Illuminate\\Database\\Eloquent\\Model;',
        "use Illuminate\\Database\\Eloquent\\Model;\nuse Other\\PostObserver;",
        plainModel(),
    );

    $result = (new ObservedByInjector())->inject($source, 'Post', OBSERVER);

    expect($result->source)->toContain('#[ObservedBy(\\App\\Observers\\PostObserver::class)]')
        ->and($result->addedImports)->toBe([OBSERVED_BY]);
});

it('ignores Foo::class and anonymous classes when locating the declaration', function () {
    $source = "<?php\n\nnamespace App\\Models;\n\nclass Post\n{\n    public function x() { return [Post::class, new class {}]; }\n}\n";

    $result = (new ObservedByInjector())->inject($source, 'Post', OBSERVER);

    expect(substr_count($result->source, '#[ObservedBy'))->toBe(1);
});

it('reports a missing class without touching the file', function () {
    $result = (new ObservedByInjector())->inject(plainModel(), 'Nope', OBSERVER);

    expect($result->status)->toBe(InjectionResult::CLASS_NOT_FOUND)->and($result->source)->toBe(plainModel());
});
