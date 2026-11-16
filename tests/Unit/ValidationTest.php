<?php

declare(strict_types=1);

use Blueprint\Models\Column;
use Blueprint\Models\Model;
use Blueprint\Tree;
use Dakotajune\BlueprintObservers\Lexers\NovaObserverLexer;
use Dakotajune\BlueprintObservers\Lexers\ObserverLexer;
use Dakotajune\BlueprintObservers\Models\Observer;
use Dakotajune\BlueprintObservers\Models\ValidationSpec;
use Dakotajune\BlueprintObservers\Snippets\SnippetException;
use Dakotajune\BlueprintObservers\Snippets\SnippetRegistry;
use Dakotajune\BlueprintObservers\Snippets\SnippetRenderer;
use Dakotajune\BlueprintObservers\Support\AutoObservers;
use Dakotajune\BlueprintObservers\Support\ObserverClassEditor;
use Dakotajune\BlueprintObservers\Support\ObserverMethodFactory;
use Dakotajune\BlueprintObservers\Support\ValidationRules;
use Dakotajune\BlueprintObservers\Tests\Support\ValidatedInvoice;
use Dakotajune\BlueprintObservers\Tests\TestCase;
use Illuminate\Validation\ValidationException;

// config() and the validator need the container, so this file runs on the Laravel test case.
uses(TestCase::class);

function validationModel(): Model
{
    $model = new Model('Invoice');
    $model->addColumn(new Column('number', 'string'));
    $model->addColumn(new Column('status', 'enum', [], ['draft', 'issued', 'paid']));
    $model->addColumn(new Column('amount', 'decimal', [], [8, 2]));
    $model->addColumn(new Column('team_id', 'id', ['foreign']));
    $model->addColumn(new Column('note', 'string', ['nullable'], [20]));

    return $model;
}

function validationTree(): Tree
{
    return new Tree(['models' => ['Invoice' => validationModel()], 'observers' => []]);
}

function validationFactory(): ObserverMethodFactory
{
    return new ObserverMethodFactory(new SnippetRenderer(new SnippetRegistry()));
}

/** Builds the observer file, loads it and returns an instance (class names must be unique per test). */
function validatingObserver(string $class, ?ValidationSpec $spec = null): object
{
    $observer = new Observer($class, 'Invoice', [], false, [], false, $spec ?? new ValidationSpec(true));

    [$methods, $imports] = validationFactory()->build(validationTree(), $observer, ValidatedInvoice::class);

    $stub = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Dakotajune\\BlueprintObservers\\Tests\\Generated;\n\nclass {$class}\n{\n}\n";
    $source = (new ObserverClassEditor())->update($stub, $class, ValidatedInvoice::class, false, $methods, $imports)->source;

    expect(lintSource($source))->toBeTrue();

    $file = tempnam(sys_get_temp_dir(), 'obs') . '.php';
    file_put_contents($file, $source);
    require $file;
    unlink($file);

    $fqcn = "Dakotajune\\BlueprintObservers\\Tests\\Generated\\{$class}";

    return new $fqcn();
}

function invoice(array $attributes, bool $exists = false, array $changed = []): ValidatedInvoice
{
    $invoice = (new ValidatedInvoice())->forceFill($attributes);

    if ($exists) {
        $invoice->exists = true;
        $invoice->syncOriginal();

        foreach ($changed as $key => $value) {
            $invoice->{$key} = $value;
        }
    }

    return $invoice;
}

function validationErrors(callable $fn): ?array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

it('derives rules from the column definitions', function () {
    $rules = (new ValidationRules())->derive(validationModel());

    expect($rules['number'])->toBe(["'required'", "'string'", "'max:255'"])
        ->and($rules['status'])->toBe(["'required'", "Rule::in(['draft', 'issued', 'paid'])"])
        ->and($rules['amount'])->toContain("'decimal:0,2'", "'max:999999.99'")
        ->and($rules['team_id'])->toBe(["'required'", "'integer'", "'min:1'"])
        ->and($rules['note'])->toBe(["'nullable'", "'string'", "'max:20'"]);
});

it('applies overrides: replace, derived, false', function () {
    $derived = (new ValidationRules())->derive(validationModel());

    $rules = (new ValidationRules())->apply($derived, new ValidationSpec(true, [
        'number' => ['required', 'max:5'],
        'note' => [ValidationSpec::DERIVED, 'alpha'],
        'amount' => false,
    ]));

    expect($rules['number'])->toBe(["'required'", "'max:5'"])
        ->and($rules['note'])->toBe(["'nullable'", "'string'", "'max:20'", "'alpha'"])
        ->and($rules)->not->toHaveKey('amount');
});

it('reads the validation key', function () {
    $lexer = new ObserverLexer(new SnippetRegistry());
    $read = fn (array $definition) => $lexer->analyze(['observers' => ['Invoice' => $definition]])['observers']['InvoiceObserver']->validation();

    expect($read([]))->toBeNull()
        ->and($read(['validation' => false])->enabled)->toBeFalse()
        ->and($read(['validation' => true])->enabled)->toBeTrue()
        ->and($read(['validation' => ['number' => 'required|max:5', 'note' => ['derived', 'min:3'], 'amount' => false]])->overrides)
        ->toBe(['number' => ['required', 'max:5'], 'note' => ['derived', 'min:3'], 'amount' => false]);

    $nova = (new NovaObserverLexer($lexer))->analyze(['nova_observers' => ['Invoice' => ['validation' => ['number' => 'required']]]]);
    expect($nova['nova_observers']['InvoiceObserver']->validation()->overrides)->toBe(['number' => ['required']]);
});

it('follows the config switch, except for Nova observers and `validation: false`', function () {
    $plain = new Observer('InvoiceObserver', 'Invoice', ['created' => []], false);
    $names = fn ($observer) => array_keys(validationFactory()->build(validationTree(), $observer, ValidatedInvoice::class)[0]);

    config(['blueprint_observers.validation.enabled' => false]);
    expect($names($plain))->toBe(['created']);

    config(['blueprint_observers.validation.enabled' => true]);
    expect($names($plain))->toBe(['created', 'saving', 'validationRules', 'validateAttributes'])
        ->and($names(new Observer('InvoiceObserver', 'Invoice', ['created' => []], false, [], false, new ValidationSpec(false))))->toBe(['created'])
        ->and($names(new Dakotajune\BlueprintObservers\Models\NovaObserver($plain)))->toBe(['created']);
});

it('fails loudly when validation is asked for on a model that is not in the draft', function () {
    validationFactory()->build(
        validationTree(),
        new Observer('GhostObserver', 'Ghost', [], false, [], false, new ValidationSpec(true)),
        'App\\Models\\Ghost',
    );
})->throws(SnippetException::class);

it('throws a ValidationException keyed by column, and by relationship for *_id columns', function () {
    $observer = validatingObserver('ValidationRuntimeOneObserver');
    $good = ['number' => 'A-1', 'status' => 'draft', 'amount' => '10.50', 'team_id' => 4];

    expect(validationErrors(fn () => $observer->saving(invoice($good))))->toBeNull();

    $errors = validationErrors(fn () => $observer->saving(invoice([...$good, 'status' => 'nope', 'team_id' => null])));

    expect($errors)->toHaveKeys(['status', 'team_id', 'team'])
        ->and($errors['team'])->toBe($errors['team_id']);
});

it('validates only what changed on an update', function () {
    $observer = validatingObserver('ValidationRuntimeTwoObserver');
    $legacy = ['number' => null, 'status' => 'draft', 'amount' => '1.00', 'team_id' => 1, 'note' => 'old'];

    expect(validationErrors(fn () => $observer->saving(invoice($legacy, true, ['note' => 'new']))))->toBeNull()
        ->and(validationErrors(fn () => $observer->saving(invoice($legacy, true, ['status' => 'nope']))))->toHaveKey('status');
});

it('creates observers just to validate when auto and validation are both on', function () {
    config(['blueprint_observers.auto.enabled' => true, 'blueprint_observers.auto.unique' => false, 'blueprint_observers.auto.guards' => false]);

    config(['blueprint_observers.validation.enabled' => false]);
    expect((new AutoObservers())->merge(validationTree(), []))->toBe([]);

    config(['blueprint_observers.validation.enabled' => true, 'blueprint_observers.auto.validation' => true]);
    expect(array_keys((new AutoObservers())->merge(validationTree(), [])['InvoiceObserver']->events()))->toBe(['saving']);

    config(['blueprint_observers.auto.validation' => false]);
    expect((new AutoObservers())->merge(validationTree(), []))->toBe([]);
});
