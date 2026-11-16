<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Support;

use Blueprint\Tree;
use Dakotajune\BlueprintObservers\Snippets\SnippetContext;
use Dakotajune\BlueprintObservers\Snippets\SnippetException;
use Dakotajune\BlueprintObservers\Snippets\SnippetMethod;
use Dakotajune\BlueprintObservers\Snippets\SnippetRenderer;
use Dakotajune\BlueprintObservers\Snippets\UniqueSnippet;
use Illuminate\Support\Str;

/**
 * Turns an observer's events (and the snippets and guards behind them) into the map the class editor wants:
 * method name => tagged method source. Event methods come first, then the helper methods the snippets and
 * guards asked for, each in its own method. Shared by the regular and the Nova generator.
 */
final class ObserverMethodFactory
{
    private readonly GuardResolver $guards;

    private readonly GuardRenderer $guardRenderer;

    private readonly ValidationRules $validationRules;

    private readonly ValidationRenderer $validationRenderer;

    public function __construct(private readonly SnippetRenderer $renderer, ?GuardResolver $guards = null, ?GuardRenderer $guardRenderer = null)
    {
        $this->guards = $guards ?? new GuardResolver();
        $this->guardRenderer = $guardRenderer ?? new GuardRenderer();
        $this->validationRules = new ValidationRules();
        $this->validationRenderer = new ValidationRenderer();
    }

    /**
     * @param  object  $observer  Observer or NovaObserver: name(), fullyQualifiedClassName(), modelName(), events(), guards(), autoGuards()
     * @return array{0: array<string,string>, 1: array<int,string>, 2: bool}
     *         method name => method source, extra imports, and whether the observer holds checks that must
     *         run before the write (so it must not be after-commit).
     */
    public function build(Tree $tree, object $observer, string $modelFqcn): array
    {
        $modelClass = class_basename($modelFqcn);
        $variable = Str::camel($modelClass);
        $blueprintModel = $tree->modelForContext($observer->modelName());
        $events = $observer->events();

        // Generated code that runs first in an event method: event => output.
        $prepend = [];

        $guards = $this->guards->resolve($tree, $observer, $blueprintModel);

        if ($guards !== []) {
            $prepend['deleting'] = $this->guardRenderer->render($guards, $variable, $modelClass);
            $events['deleting'] ??= [];
        }

        $validation = $this->validationRulesFor($observer, $blueprintModel);

        if ($validation !== []) {
            $prepend['saving'] = $this->validationRenderer->render($validation, $variable, $modelClass);
            $events['saving'] ??= [];
        }

        $blocking = $prepend !== [];
        $methods = [];
        $helpers = [];
        $imports = [];

        foreach ($events as $event => $snippets) {
            $rendered = $this->renderer->render($snippets, new SnippetContext(
                observer: $observer->name(),
                observerFqcn: $observer->fullyQualifiedClassName(),
                model: $modelClass,
                modelFqcn: $modelFqcn,
                variable: $variable,
                event: $event,
                blueprintModel: $blueprintModel,
            ));

            $body = $rendered['body'];
            $extra = $rendered['methods'];
            $moreImports = $rendered['imports'];

            if (isset($prepend[$event])) {
                // Guards and validation run first: they may refuse the write before anything else happens.
                $generated = $prepend[$event];
                $body = $body === '' ? $generated->body : $generated->body . "\n\n" . $body;
                $extra = [...$generated->methods, ...$extra];
                $moreImports = [...$generated->imports, ...$moreImports];
            }

            if (in_array(UniqueSnippet::NAME, $snippets, true)) {
                $blocking = true;
            }

            $methods[$event] = ObserverMethodBuilder::build($event, $modelClass, $variable, $body);

            /** @var SnippetMethod $helper */
            foreach ($extra as $helper) {
                $known = $helpers[$helper->name] ?? null;

                if ($known !== null && $known->body !== $helper->body) {
                    throw new SnippetException("{$observer->name()} gets two different methods called [{$helper->name}] from its snippets.");
                }

                $helpers[$helper->name] = $helper;
            }

            $imports = [...$imports, ...$moreImports];
        }

        foreach ($helpers as $helper) {
            $methods[$helper->name] = ObserverMethodBuilder::buildHelper($helper->name, $helper->summary, $variable, $helper->body, $helper->returnType);
        }

        return [$methods, array_values(array_unique($imports)), $blocking];
    }

    /**
     * The validation rules this observer enforces, as expressions, or [] when it does not validate.
     *
     * On when the observer's `validation:` key says so, or, without the key, when config
     * `validation.enabled` is on (regular observers only; see NovaObserver::inheritsConfigValidation()).
     *
     * @return array<string, array<int, string>>
     */
    private function validationRulesFor(object $observer, ?object $blueprintModel): array
    {
        $spec = $observer->validation();

        $enabled = $spec !== null
            ? $spec->enabled
            : $observer->inheritsConfigValidation() && (bool) config('blueprint_observers.validation.enabled', false);

        if (! $enabled) {
            return [];
        }

        $derived = $blueprintModel === null ? [] : $this->validationRules->derive($blueprintModel);

        if ($blueprintModel === null && $spec !== null && $spec->overrides === []) {
            throw new SnippetException(
                "{$observer->name()} asks for validation, but its model [{$observer->modelName()}] is not in the draft's models: "
                . 'block, so there is nothing to derive rules from. Define the model, or list the rules under validation:.',
            );
        }

        return $this->validationRules->apply($derived, $spec);
    }
}
