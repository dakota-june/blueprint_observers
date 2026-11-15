<?php

declare(strict_types=1);

use Dakotajune\Blueclues\Lexers\ObserverLexer;

it('ignores drafts without an observers block', function () {
    expect((new ObserverLexer())->analyze(['models' => ['Post' => ['title' => 'string']]]))->toBe([]);
});

it('parses the observers block', function () {
    $tokens = ['observers' => [
        'payment' => ['model' => 'payment', 'afterCommit' => false, 'events' => ['creating', 'updated']],
        'PostObserver' => null,
        'Audit' => ['model' => 'Admin\\user', 'events' => 'saved, deleted'],
    ]];

    $observers = (new ObserverLexer())->analyze($tokens)['observers'];

    expect(array_keys($observers))->toBe(['PaymentObserver', 'PostObserver', 'AuditObserver'])
        ->and($observers['PaymentObserver']->modelName())->toBe('Payment')
        ->and($observers['PaymentObserver']->afterCommit())->toBeFalse()
        ->and($observers['PaymentObserver']->events())->toBe(['creating', 'updated'])
        ->and($observers['PostObserver']->modelName())->toBe('Post')
        ->and($observers['PostObserver']->events())->toBe(['creating', 'updating', 'deleted'])
        ->and($observers['PostObserver']->afterCommit())->toBeTrue()
        ->and($observers['AuditObserver']->modelName())->toBe('Admin\\User')
        ->and($observers['AuditObserver']->events())->toBe(['saved', 'deleted'])
        ->and($observers['AuditObserver']->fullyQualifiedClassName())->toBe('App\\Observers\\AuditObserver');
});

it('rejects unknown events instead of silently dropping them', function () {
    (new ObserverLexer())->analyze(['observers' => ['Post' => ['events' => ['creating', 'exploded']]]]);
})->throws(InvalidArgumentException::class, 'exploded');

it('rejects observers that normalise to the same name', function () {
    (new ObserverLexer())->analyze(['observers' => ['post' => [], 'PostObserver' => []]]);
})->throws(InvalidArgumentException::class, 'more than once');
