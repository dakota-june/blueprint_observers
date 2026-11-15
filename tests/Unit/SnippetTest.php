<?php

declare(strict_types=1);

use Dakotajune\Blueclues\Snippets\SnippetContext;
use Dakotajune\Blueclues\Snippets\SnippetException;
use Dakotajune\Blueclues\Snippets\SnippetRegistry;
use Dakotajune\Blueclues\Snippets\SnippetRenderer;
use Dakotajune\Blueclues\Tests\Support\AuditSnippet;
use Dakotajune\Blueclues\Tests\Support\BrokenSnippet;

function snippetContext(string $event = 'creating'): SnippetContext
{
    return new SnippetContext('PostObserver', 'App\\Observers\\PostObserver', 'Post', 'App\\Models\\Post', 'post', $event);
}

it('rejects duplicate and badly named snippets', function () {
    $registry = new SnippetRegistry();
    $registry->register(new AuditSnippet());

    expect(fn () => $registry->register(new AuditSnippet()))->toThrow(LogicException::class)
        ->and(fn () => $registry->get('nope'))->toThrow(InvalidArgumentException::class)
        ->and($registry->has('audit'))->toBeTrue()->and($registry->has('nope'))->toBeFalse();
});

it('renders opted-in snippets in order and collects imports', function () {
    $registry = new SnippetRegistry();
    $registry->register(new AuditSnippet());

    $result = (new SnippetRenderer($registry))->render(['audit'], snippetContext());

    expect($result['body'])->toBe("Log::info('creating', ['id' => \$post->getKey()]);")
        ->and($result['imports'])->toBe(['Illuminate\\Support\\Facades\\Log']);
});

it('refuses a snippet on an event it does not support', function () {
    $registry = new SnippetRegistry();
    $registry->register(new AuditSnippet());

    (new SnippetRenderer($registry))->render(['audit'], snippetContext('deleted'));
})->throws(SnippetException::class, 'does not support');

it('names the snippet whose output is not valid PHP', function () {
    $registry = new SnippetRegistry();
    $registry->register(new BrokenSnippet());

    (new SnippetRenderer($registry))->render(['broken'], snippetContext());
})->throws(SnippetException::class, '[broken]');
