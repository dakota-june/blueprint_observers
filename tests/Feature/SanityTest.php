<?php

declare(strict_types=1);

use Blueprint\Blueprint;
use Dakotajune\BlueprintObservers\Snippets\SnippetRegistry;
use Dakotajune\BlueprintObservers\Support\NoticeBag;
use Dakotajune\BlueprintObservers\Tests\Support\AuditSnippet;

/** Writes straight to the terminal (bypasses PHPUnit's output buffering). */
function say(string $text = ''): void
{
    fwrite(STDOUT, $text . PHP_EOL);
}

it('builds examples/draft.yaml and shows the generated observers', function () {
    $draft = dirname(__DIR__, 2) . '/examples/draft.yaml';
    expect(is_file($draft))->toBeTrue();

    // examples/draft.yaml opts in to a snippet called "audit"; register the demo one the tests use.
    app(SnippetRegistry::class)->register(new AuditSnippet());

    $blueprint = app(Blueprint::class);
    $tree = $blueprint->analyze($blueprint->parse(file_get_contents($draft)));

    // Models first (so the observers have something to attach to), then the observers.
    $generated = $blueprint->generate($tree, ['models', 'observers']);

    say();
    say(str_repeat('=', 78));
    say('DRAFT: ' . $draft);
    say('PROJECT (throwaway): ' . $this->project);
    say(str_repeat('=', 78));
    say('build result: ' . json_encode($generated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $observers = glob($this->project . '/app/Observers/*.php') ?: [];
    sort($observers);

    foreach ($observers as $file) {
        say();
        say('--- app/Observers/' . basename($file) . ' ' . str_repeat('-', 40));
        say(rtrim(file_get_contents($file)));
    }

    foreach (glob($this->project . '/app/Models/*.php') ?: [] as $file) {
        say();
        say('--- app/Models/' . basename($file) . ' (attribute wiring) ' . str_repeat('-', 20));
        foreach (preg_split('/\R/', file_get_contents($file)) as $line) {
            if (preg_match('/^(namespace|use) |ObservedBy|^(final |abstract )?class /', $line)) {
                say($line);
            }
        }
    }

    // Rebuild: the observers must come out byte-for-byte the same.
    $before = array_map('md5_file', $observers);
    $blueprint->generate($tree, ['models', 'observers']);
    $after = array_map('md5_file', $observers);

    say();
    say('rebuild leaves observers unchanged: ' . ($before === $after ? 'YES' : 'NO'));

    foreach (app(NoticeBag::class)->pull() as $notice) {
        say('notice: ' . $notice);
    }
    say(str_repeat('=', 78));

    expect(array_map('basename', $observers))->toBe(['InvoiceObserver.php', 'PaymentObserver.php'])
        ->and(file_get_contents("{$this->project}/app/Observers/PaymentObserver.php"))
        ->toContain('@generated blueprint_observers')
        ->toContain("Log::info('creating'")
        ->and($before)->toBe($after);

    foreach ($observers as $file) {
        expect(lintSource(file_get_contents($file)))->toBeTrue();
    }
});