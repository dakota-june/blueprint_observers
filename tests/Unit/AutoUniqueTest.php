<?php

declare(strict_types=1);

use Blueprint\Models\Column;
use Blueprint\Models\Index;
use Blueprint\Tree;
use Dakotajune\BlueprintObservers\Models\Observer;
use Dakotajune\BlueprintObservers\Snippets\SnippetRegistry;
use Dakotajune\BlueprintObservers\Snippets\SnippetRenderer;
use Dakotajune\BlueprintObservers\Snippets\UniqueSnippet;
use Dakotajune\BlueprintObservers\Support\AutoObservers;
use Dakotajune\BlueprintObservers\Support\ObserverMethodFactory;
use Dakotajune\BlueprintObservers\Support\UniqueConstraints;
use Dakotajune\BlueprintObservers\Tests\TestCase;

// config() needs the container, so this file runs on the Laravel test case.
uses(TestCase::class);

function autoUniqueModel(array $columns, array $indexes = []): object
{
    return new class($columns, $indexes) {
        public function __construct(private array $columns, private array $indexes) {}

        public function columns(): array { return $this->columns; }

        public function indexes(): array { return $this->indexes; }

        public function hasColumn(string $n): bool { return isset($this->columns[$n]); }

        public function relationships(): array { return []; }

        public function usesSoftDeletes(): bool { return false; }

        public function primaryKey(): string { return 'id'; }

        public function name(): string { return 'Model'; }
    };
}

it('finds unique columns and unique indexes', function () {
    $model = autoUniqueModel(
        ['number' => new Column('number', 'string', ['unique']), 'slug' => new Column('slug', 'string')],
        [new Index('unique', ['tenant_id', 'slug']), new Index('index', ['slug'])],
    );

    expect(UniqueConstraints::for($model))->toBe([['number'], ['tenant_id', 'slug']]);
});

it('adds observers only when enabled and only for models with unique constraints', function () {
    $tree = new Tree(['models' => [
        'Invoice' => autoUniqueModel(['number' => new Column('number', 'string', ['unique'])]),
        'Tag' => autoUniqueModel(['name' => new Column('name', 'string')]),
    ], 'observers' => []]);

    config(['blueprint_observers.auto.enabled' => false]);
    expect((new AutoObservers())->merge($tree, []))->toBe([]);

    config(['blueprint_observers.auto.enabled' => true, 'blueprint_observers.auto.unique' => false]);
    expect((new AutoObservers())->merge($tree, []))->toBe([]);

    config(['blueprint_observers.auto.unique' => true]);
    $merged = (new AutoObservers())->merge($tree, []);

    expect(array_keys($merged))->toBe(['InvoiceObserver'])
        ->and($merged['InvoiceObserver']->afterCommit())->toBeFalse()
        ->and(array_keys($merged['InvoiceObserver']->events()))->toBe(['creating', 'updating']);
});

it('puts every unique constraint in its own method', function () {
    $tree = new Tree(['models' => ['Invoice' => autoUniqueModel(
        ['number' => new Column('number', 'string', ['unique'])],
        [new Index('unique', ['tenant_id', 'slug'])],
    )], 'observers' => []]);

    $registry = new SnippetRegistry();
    $registry->register(new UniqueSnippet());

    [$methods, $imports] = (new ObserverMethodFactory(new SnippetRenderer($registry)))->build(
        $tree,
        new Observer('InvoiceObserver', 'Invoice', ['creating' => ['unique'], 'updating' => ['unique']], false),
        'App\\Models\\Invoice',
    );

    expect(array_keys($methods))->toBe(['creating', 'updating', 'ensureUniqueNumber', 'ensureUniqueTenantIdAndSlug'])
        ->and($imports)->toBe(['Illuminate\\Validation\\ValidationException']);
});
