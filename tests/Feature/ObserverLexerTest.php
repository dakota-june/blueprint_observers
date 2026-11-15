<?php

declare(strict_types=1);

use Dakotajune\BlueprintObservers\Lexers\ObserverLexer;
use Dakotajune\BlueprintObservers\Snippets\SnippetRegistry;
use Dakotajune\BlueprintObservers\Tests\Support\AuditSnippet;

function lexer(): ObserverLexer
{
    $registry = app(SnippetRegistry::class);
    $registry->has('audit') || $registry->register(new AuditSnippet());

    return app(ObserverLexer::class);
}

it('ignores drafts without an observers block', function () {
    expect(lexer()->analyze(['models' => ['Post' => ['title' => 'string']]]))->toBe([]);
});

it('parses the observers block with per-event snippet opt-in', function () {
    $observers = lexer()->analyze(['observers' => [
        'payment' => ['model' => 'payment', 'afterCommit' => false, 'events' => ['creating' => ['audit'], 'updated' => null]],
        'PostObserver' => null,
        'Audit' => ['model' => 'Admin\\user', 'events' => 'saved, deleted'],
    ]])['observers'];

    expect(array_keys($observers))->toBe(['PaymentObserver', 'PostObserver', 'AuditObserver'])
        ->and($observers['PaymentObserver']->events())->toBe(['creating' => ['audit'], 'updated' => []])
        ->and($observers['PaymentObserver']->afterCommit())->toBeFalse()
        ->and($observers['PostObserver']->eventNames())->toBe(['creating', 'updating', 'deleted'])
        ->and($observers['AuditObserver']->modelName())->toBe('Admin\\User')
        ->and($observers['AuditObserver']->eventNames())->toBe(['saved', 'deleted'])
        ->and($observers['AuditObserver']->fullyQualifiedClassName())->toBe('App\\Observers\\AuditObserver');
});

it('copes with Blueprint stripping list dashes (block lists arrive as one string)', function () {
    $observer = lexer()->analyze(['observers' => ['Post' => ['events' => "creating\n  updated"]]])['observers']['PostObserver'];

    expect($observer->eventNames())->toBe(['creating', 'updated']);
});

it('treats false as "do not generate this event" and true as "no snippets"', function () {
    $observer = lexer()->analyze(['observers' => ['Payment' => ['events' => [
        'creating' => ['audit'],
        'updated' => null,
        'saved' => true,
        'deleted' => false,
    ]]]])['observers']['PaymentObserver'];

    expect($observer->events())->toBe(['creating' => ['audit'], 'updated' => [], 'saved' => []]);
});

it('requires snippets to be written inside square brackets', function () {
    lexer()->analyze(['observers' => ['Post' => ['events' => ['creating' => 'audit']]]]);
})->throws(InvalidArgumentException::class, 'square brackets');

it('rejects bad drafts with a clear message', function (array $events) {
    lexer()->analyze(['observers' => ['Post' => ['events' => $events]]]);
})->throws(InvalidArgumentException::class)->with([
    'unknown snippet' => [['creating' => ['nope']]],
    'unsupported event' => [['deleted' => ['audit']]],
    'duplicate snippet' => [['creating' => ['audit', 'audit']]],
    'unknown event' => [['exploded' => []]],
    'non-text snippet name' => [['creating' => [['nested']]]],
]);

it('rejects observers that normalise to the same name', function () {
    lexer()->analyze(['observers' => ['post' => [], 'PostObserver' => []]]);
})->throws(InvalidArgumentException::class, 'more than once');
