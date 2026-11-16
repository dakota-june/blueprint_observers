<?php

declare(strict_types=1);

use Dakotajune\BlueprintObservers\Lexers\NovaObserverLexer;
use Dakotajune\BlueprintObservers\Models\NovaObserver;

function novaLexer(): NovaObserverLexer
{
    return app(NovaObserverLexer::class);
}

it('ignores drafts without a nova_observers block', function () {
    expect(novaLexer()->analyze(['models' => ['Post' => ['title' => 'string']]]))->toBe([])
        ->and(novaLexer()->analyze(['nova_observers' => []]))->toBe([]);
});

it('parses names, models, events and afterCommit like the observers block', function () {
    $observers = novaLexer()->analyze(['nova_observers' => [
        'Invoice' => null,
        'payment' => ['model' => 'payment', 'afterCommit' => false, 'events' => ['creating' => null, 'updated' => null]],
    ]])['nova_observers'];

    expect(array_keys($observers))->toBe(['InvoiceObserver', 'PaymentObserver'])
        ->and($observers['InvoiceObserver']->eventNames())->toBe(['creating', 'updating', 'deleted'])
        ->and($observers['PaymentObserver']->eventNames())->toBe(['creating', 'updated'])
        ->and($observers['PaymentObserver']->afterCommit())->toBeFalse()
        ->and($observers['PaymentObserver']->modelName())->toBe('Payment')
        ->and($observers['PaymentObserver']->fullyQualifiedClassName())->toBe('App\\Nova\\Observers\\PaymentObserver');
});

it('understands extends: omitted, ~, true, false and a class name', function () {
    $observers = novaLexer()->analyze(['nova_observers' => [
        'Invoice' => null,
        'Payment' => ['extends' => null],
        'Comment' => ['extends' => true],
        'Tag' => ['extends' => false],
        'Post' => ['extends' => '\\App\\Support\\BaseObserver'],
    ]])['nova_observers'];

    expect($observers['InvoiceObserver']->parentClass())->toBeNull()
        ->and($observers['PaymentObserver']->extendsKind())->toBe(NovaObserver::EXTENDS_REGULAR)
        ->and($observers['PaymentObserver']->parentClass())->toBe('App\\Observers\\PaymentObserver')
        ->and($observers['CommentObserver']->parentClass())->toBe('App\\Observers\\CommentObserver')
        ->and($observers['TagObserver']->parentClass())->toBeNull()
        ->and($observers['PostObserver']->parentClass())->toBe('App\\Support\\BaseObserver');
});

it('rejects bad drafts with a clear message', function (array $definition, string $message) {
    novaLexer()->analyze(['nova_observers' => ['Post' => $definition]]);
})->throws(InvalidArgumentException::class)->with([
    'unknown key' => [['extend' => null], 'unknown key'],
    'bad extends' => [['extends' => 'not a class!'], 'invalid extends'],
    'non-text extends' => [['extends' => 5], 'invalid extends'],
    'unknown event' => [['events' => ['exploded' => []]], 'unknown event'],
]);

it('rejects nova observers that normalise to the same name', function () {
    novaLexer()->analyze(['nova_observers' => ['post' => [], 'PostObserver' => []]]);
})->throws(InvalidArgumentException::class, 'more than once');
