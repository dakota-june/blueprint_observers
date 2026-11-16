<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Generators;

use Blueprint\Blueprint;
use Blueprint\Contracts\Generator;
use Blueprint\Tree;
use Dakotajune\BlueprintObservers\Models\NovaObserver;
use Dakotajune\BlueprintObservers\Snippets\SnippetRegistry;
use Dakotajune\BlueprintObservers\Snippets\SnippetRenderer;
use Dakotajune\BlueprintObservers\Support\Manifest;
use Dakotajune\BlueprintObservers\Support\ManifestStore;
use Dakotajune\BlueprintObservers\Support\NoticeBag;
use Dakotajune\BlueprintObservers\Support\NovaProviderEditor;
use Dakotajune\BlueprintObservers\Support\ObserverClassEditor;
use Dakotajune\BlueprintObservers\Support\ObserverMethodFactory;
use Illuminate\Filesystem\Filesystem;

/**
 * Generates the observers of the `nova_observers:` block (app/Nova/Observers by default) and registers
 * them from a generated `blueprintObservers()` method on the Nova service provider.
 *
 * Observer files are written exactly like the regular ones (same ownership tag, same in-place edits).
 * The provider is only ever touched through NovaProviderEditor.
 */
final class NovaObserverGenerator implements Generator
{
    private readonly ObserverMethodFactory $factory;

    private readonly ObserverClassEditor $editor;

    private readonly NovaProviderEditor $provider;

    private readonly ManifestStore $manifests;

    private readonly NoticeBag $notices;

    public function __construct(
        private readonly Filesystem $files,
        ?SnippetRegistry $registry = null,
        ?NoticeBag $notices = null,
        ?ManifestStore $manifests = null,
        ?ObserverClassEditor $editor = null,
        ?NovaProviderEditor $provider = null,
    ) {
        $this->factory = new ObserverMethodFactory(new SnippetRenderer($registry ?? new SnippetRegistry()));
        $this->notices = $notices ?? new NoticeBag();
        $this->manifests = $manifests ?? new ManifestStore($files);
        $this->editor = $editor ?? new ObserverClassEditor();
        $this->provider = $provider ?? new NovaProviderEditor();
    }

    /** @return array{created: array<int,string>, updated: array<int,string>} */
    public function output(Tree $tree): array
    {
        $output = ['created' => [], 'updated' => []];

        /** @var array<string, NovaObserver> $observers */
        $observers = $tree->toArray()['nova_observers'] ?? [];

        $previous = $this->manifests->load();

        if ($observers === []) {
            // The block was removed since the last build: take the registration out of the provider again.
            if ($previous->providers() !== [] || $previous->novaObservers() !== []) {
                $this->unregister($previous, $output);
                $this->manifests->save($previous->withoutNova());
            }

            return $output;
        }

        $next = $previous->withoutNova();
        $registrations = [];

        foreach ($observers as $observer) {
            $path = $this->relativePath($observer->fullyQualifiedClassName());
            $modelFqcn = $tree->fqcnForContext($observer->modelName());

            $existed = $this->files->exists(base_path($path));
            $created = ! $existed || ($previous->novaObserver($path)['created'] ?? false);

            $this->writeObserver($tree, $observer, $path, $modelFqcn, $existed);

            $output[$created ? 'created' : 'updated'][] = $path;
            $next->recordNovaObserver($path, $observer->name(), $created);

            $registrations[] = ['model' => $modelFqcn, 'observer' => $observer->fullyQualifiedClassName()];
        }

        $this->register($registrations, $previous, $next, $output);

        $this->manifests->save($next);

        return $output;
    }

    /** @return array<int, string> */
    public function types(): array
    {
        return ['nova_observers'];
    }

    // =====================================================================================================
    // Observer files
    // =====================================================================================================

    private function writeObserver(Tree $tree, NovaObserver $observer, string $path, string $modelFqcn, bool $existed): void
    {
        $absolute = base_path($path);
        $imports = [];

        if ($existed) {
            $source = $this->files->get($absolute);

            if ($observer->parentClass() !== null
                && ! preg_match('/\bclass\s+' . preg_quote($observer->name(), '/') . '\s+extends\s/', $source)) {
                $this->notices->add("{$path} does not extend a class. `extends` is only applied when the file is first created, so add it yourself if you want it.");
            }
        } else {
            [$source, $parentImport] = $this->newFile($tree, $observer);

            if ($parentImport !== null) {
                $imports[] = $parentImport;
            }
        }

        [$methods, $snippetImports, $blocking] = $this->methods($tree, $observer, $modelFqcn);

        $result = $this->editor->update(
            $source,
            $observer->name(),
            $modelFqcn,
            $observer->afterCommit() && ! $blocking,
            $methods,
            [...$imports, ...$snippetImports],
        );

        foreach ($result->notices as $notice) {
            $this->notices->add($notice);
        }

        if (! $existed) {
            $this->files->ensureDirectoryExists(dirname($absolute));
        }

        if (! $existed || $result->changed) {
            $this->files->put($absolute, $result->source);
        }
    }

    /**
     * @return array{0: array<string,string>, 1: array<int,string>, 2: bool}  method name => method source, extra imports, must-run-before-write
     */
    private function methods(Tree $tree, NovaObserver $observer, string $modelFqcn): array
    {
        return $this->factory->build($tree, $observer, $modelFqcn);
    }

    /**
     * @return array{0: string, 1: string|null}  file source, and the parent class to import (if any)
     */
    private function newFile(Tree $tree, NovaObserver $observer): array
    {
        $stub = $this->files->get($this->stubPath());
        $clause = '';
        $import = null;
        $parent = $observer->parentClass();

        if ($parent !== null) {
            if (! str_contains($stub, '{{ extends }}')) {
                $this->notices->add('Your stubs/blueprint/nova_observer.stub has no {{ extends }} placeholder, so '
                    . "{$observer->name()} was generated without `extends {$parent}`.");
            } elseif (class_basename($parent) === $observer->name()) {
                // Same short name as the class being declared (e.g. extends the regular PostObserver): no import.
                $clause = ' extends \\' . $parent;
            } else {
                $clause = ' extends ' . class_basename($parent);
                $import = $parent;
            }

            if ($observer->extendsKind() === NovaObserver::EXTENDS_REGULAR) {
                $this->warnIfRegularObserverIsMissing($tree, $observer, $parent);
            }
        }

        $source = str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ extends }}'],
            [$observer->fullyQualifiedNamespace(), $observer->name(), $clause],
            $stub,
        );

        return [$source, $import];
    }

    private function warnIfRegularObserverIsMissing(Tree $tree, NovaObserver $observer, string $parent): void
    {
        $regular = $tree->toArray()['observers'] ?? [];

        if (isset($regular[$observer->name()]) || $this->files->exists(base_path($this->relativePath($parent)))) {
            return;
        }

        $this->notices->add("{$observer->name()} extends [{$parent}], but it is not in the draft's observers: block "
            . 'and no such file exists yet.');
    }

    // =====================================================================================================
    // Nova service provider
    // =====================================================================================================

    /**
     * @param  array<int, array{model:string, observer:string}>  $registrations
     * @param  array{created: array<int,string>, updated: array<int,string>}  $output
     */
    private function register(array $registrations, Manifest $previous, Manifest $next, array &$output): void
    {
        $fqcn = $this->providerClassName();
        $path = $this->relativePath($fqcn);
        $absolute = base_path($path);

        if (! $this->files->exists($absolute)) {
            $this->notices->add("Nova provider [{$path}] was not found, so the observers were not registered. "
                . 'Call Laravel\Nova\Observable::make(Model::class, Observer::class) for each one yourself, '
                . 'or set blueprint_observers.nova_provider.');

            return;
        }

        $class = class_basename($fqcn);
        $result = $this->provider->update($this->files->get($absolute), $class, $registrations);

        foreach ($result->notices as $notice) {
            $this->notices->add($notice);
        }

        if ($result->changed) {
            $this->files->put($absolute, $result->source);
            $output['updated'][] = $path;
        }

        // Imports we added on earlier builds stay recorded, so erase can still clean them up.
        $imports = array_values(array_unique([...($previous->provider($path)['imports'] ?? []), ...$result->addedImports]));
        $next->recordProvider($path, $class, $imports);
    }

    /** @param  array{created: array<int,string>, updated: array<int,string>}  $output */
    private function unregister(Manifest $previous, array &$output): void
    {
        foreach ($previous->providers() as $path => $meta) {
            $absolute = base_path($path);

            if (! $this->files->exists($absolute)) {
                continue;
            }

            $original = $this->files->get($absolute);
            $stripped = $this->provider->strip($original, $meta['class'], $meta['imports']);

            if ($stripped !== $original) {
                $this->files->put($absolute, $stripped);
                $output['updated'][] = $path;
            }
        }
    }

    private function providerClassName(): string
    {
        return trim(
            (string) config('blueprint.namespace', 'App') . '\\'
            . trim((string) config('blueprint_observers.nova_provider', 'Providers\\NovaServiceProvider'), '\\'),
            '\\',
        );
    }

    /** Path relative to the project root, built with Blueprint's own helpers. */
    private function relativePath(string $fullyQualifiedClassName): string
    {
        return Blueprint::appPath() . '/'
            . str_replace('\\', '/', Blueprint::relativeNamespace($fullyQualifiedClassName))
            . '.php';
    }

    private function stubPath(): string
    {
        $custom = base_path('stubs/blueprint/nova_observer.stub');

        return $this->files->exists($custom) ? $custom : dirname(__DIR__, 2) . '/stubs/nova_observer.stub';
    }
}
