<?php

declare(strict_types=1);

use Blueprint\Models\Column;
use Blueprint\Models\Model;
use Blueprint\Tree;
use Dakotajune\BlueprintObservers\Lexers\ObserverLexer;
use Dakotajune\BlueprintObservers\Models\Guard;
use Dakotajune\BlueprintObservers\Models\Observer;
use Dakotajune\BlueprintObservers\Snippets\SnippetRegistry;
use Dakotajune\BlueprintObservers\Snippets\SnippetRenderer;
use Dakotajune\BlueprintObservers\Snippets\UniqueSnippet;
use Dakotajune\BlueprintObservers\Support\AutoObservers;
use Dakotajune\BlueprintObservers\Support\GuardResolver;
use Dakotajune\BlueprintObservers\Support\ObserverMethodFactory;
use Dakotajune\BlueprintObservers\Tests\TestCase;

// config() needs the container, so this file runs on the Laravel test case.
uses(TestCase::class);

function guardsTree(array $paymentModifiers = [], bool $paymentsSoft = false, array $relationships = ['hasMany' => 'Payment']): Tree
{
    $invoice = new Model('Invoice');
    $invoice->addColumn(new Column('id', 'id'));
    foreach ($relationships as $type => $reference) {
        $invoice->addRelationship($type, $reference);
    }

    $payment = new Model('Payment');
    $payment->addColumn(new Column('invoice_id', 'id', $paymentModifiers));
    if ($paymentsSoft) {
        $payment->enableSoftDeletes();
    }

    return new Tree(['models' => ['Invoice' => $invoice, 'Payment' => $payment], 'observers' => []]);
}

function guardsFactory(): ObserverMethodFactory
{
    $registry = new SnippetRegistry();
    $registry->register(new UniqueSnippet());

    return new ObserverMethodFactory(new SnippetRenderer($registry));
}

it('reads guards from the draft', function () {
    $lexer = new ObserverLexer(new SnippetRegistry());

    $observer = $lexer->analyze(['observers' => ['Invoice' => ['guards' => [
        'payments' => 'restrict',
        'line_items' => ['action' => 'cascade', 'trashed' => 'ignore'],
        'notes' => false,
        'auto' => true,
    ]]]])['observers']['InvoiceObserver'];

    expect($observer->autoGuards())->toBeTrue()
        ->and($observer->guards()['payments']->action)->toBe('restrict')
        ->and($observer->guards()['lineItems']->trashed)->toBe('ignore')
        ->and($observer->guards()['notes'])->toBeNull();
});

it('rejects unknown guard actions and keys', function (array $guards) {
    (new ObserverLexer(new SnippetRegistry()))->analyze(['observers' => ['Invoice' => ['guards' => $guards]]]);
})->with([
    'action' => [['payments' => 'explode']],
    'key' => [['payments' => ['when' => 'always']]],
    'trashed' => [['payments' => ['trashed' => 'maybe']]],
])->throws(InvalidArgumentException::class);

it('derives the action from the child foreign key, falling back to config', function () {
    $actions = fn (array $modifiers) => array_map(
        fn (Guard $guard) => $guard->action,
        (new GuardResolver())->derive($tree = guardsTree($modifiers), $tree->modelForContext('Invoice')),
    );

    expect($actions([]))->toBe(['payments' => 'restrict'])
        ->and($actions([['onDelete' => 'cascade']]))->toBe(['payments' => 'cascade'])
        ->and($actions([['onDelete' => 'null']]))->toBe(['payments' => 'nullify']);

    config(['blueprint_observers.auto.guard_default' => 'cascade']);
    expect($actions([]))->toBe(['payments' => 'cascade']);
});

it('puts each guard in its own method, restrict first', function () {
    $observer = new Observer('InvoiceObserver', 'Invoice', [], true, [
        'payments' => new Guard('cascade'),
        'notes' => new Guard('restrict'),
    ]);

    [$methods, $imports, $blocking] = guardsFactory()->build(guardsTree(), $observer, 'App\\Models\\Invoice');

    expect(array_keys($methods))->toBe(['deleting', 'restrictNotes', 'cascadePayments'])
        ->and($blocking)->toBeTrue()
        ->and($imports)->toContain('Illuminate\\Validation\\ValidationException')
        ->and($methods['deleting'])->toContain('$this->restrictNotes($invoice);');
});

it('counts trashed children unless told not to', function () {
    $build = fn (Guard $guard) => guardsFactory()->build(
        guardsTree([], true),
        new Observer('InvoiceObserver', 'Invoice', [], true, ['payments' => $guard]),
        'App\\Models\\Invoice',
    )[0]['restrictPayments'];

    expect($build(new Guard('restrict')))->toContain('->withTrashed()')
        ->and($build(new Guard('restrict', 'ignore')))->not->toContain('withTrashed');

    config(['blueprint_observers.guards.trashed' => 'ignore']);
    expect($build(new Guard('restrict')))->not->toContain('withTrashed')
        ->and($build(new Guard('restrict', 'block')))->toContain('->withTrashed()');
});

it('refuses cascade on relationships that are not hasMany or hasOne', function () {
    guardsFactory()->build(
        guardsTree([], false, ['belongsToMany' => 'Tag']),
        new Observer('InvoiceObserver', 'Invoice', [], true, ['tags' => new Guard('cascade')]),
        'App\\Models\\Invoice',
    );
})->throws(Dakotajune\BlueprintObservers\Snippets\SnippetException::class);

it('auto observers also cover relationships, and both behaviours can be switched off', function () {
    config(['blueprint_observers.auto.enabled' => true, 'blueprint_observers.auto.unique' => true, 'blueprint_observers.auto.guards' => true]);
    $tree = guardsTree();

    $observer = (new AutoObservers())->merge($tree, [])['InvoiceObserver'];
    expect(array_keys($observer->events()))->toBe(['deleting'])
        ->and($observer->autoGuards())->toBeTrue()
        ->and($observer->afterCommit())->toBeFalse();

    config(['blueprint_observers.auto.guards' => false]);
    expect((new AutoObservers())->merge($tree, []))->toBe([]);
});
