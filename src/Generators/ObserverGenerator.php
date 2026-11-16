<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Generators;

use Blueprint\Blueprint;
use Blueprint\Contracts\Generator;
use Blueprint\Tree;
use Dakotajune\BlueprintObservers\Models\Observer;
use Dakotajune\BlueprintObservers\Snippets\SnippetContext;
use Dakotajune\BlueprintObservers\Snippets\SnippetRegistry;
use Dakotajune\BlueprintObservers\Snippets\SnippetRenderer;
use Dakotajune\BlueprintObservers\Support\Manifest;
use Dakotajune\BlueprintObservers\Support\ManifestStore;
use Dakotajune\BlueprintObservers\Support\NoticeBag;
use Dakotajune\BlueprintObservers\Support\ObservedByInjector;
use Dakotajune\BlueprintObservers\Support\ObserverClassEditor;
use Dakotajune\BlueprintObservers\Support\ObserverMethodBuilder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class ObserverGenerator implements Generator
{
    private readonly SnippetRenderer $renderer;

    private readonly ObserverClassEditor $editor;

    private readonly ObservedByInjector $injector;

    private readonly ManifestStore $manifests;

    private readonly NoticeBag $notices;

    // The Generator contract fixes the first parameter; the rest are optional collaborators.
    public function __construct(
        private readonly Filesystem $files,
        ?SnippetRegistry $registry = null,
        ?NoticeBag $notices = null,
        ?ManifestStore $manifests = null,
        ?ObserverClassEditor $editor = null,
        ?ObservedByInjector $injector = null,
    ) {
        $this->renderer = new SnippetRenderer($registry ?? new SnippetRegistry());
        $this->notices = $notices ?? new NoticeBag();
        $this->manifests = $manifests ?? new ManifestStore($files);
        $this->editor = $editor ?? new ObserverClassEditor();
        $this->injector = $injector ?? new ObservedByInjector();
    }

    /** @return array{created: array<int,string>, updated: array<int,string>} */
    public function output(Tree $tree): array
    {
        $output = ['created' => [], 'updated' => []];

        /** @var array<string, Observer> $observers */
        $observers = $tree->toArray()['observers'] ?? [];

        if ($observers === []) {
            return $output;
        }

        $previous = $this->manifests->load();
        // The Nova generator shares this manifest; keep what it recorded last time.
        $next = (new Manifest())->carryNovaFrom($previous);

        foreach ($observers as $observer) {
            $path = $this->relativePath($observer->fullyQualifiedClassName());
            $modelFqcn = $tree->fqcnForContext($observer->modelName());

            $existed = $this->files->exists(base_path($path));
            // We "own" the file (and may report it as created, so erase can delete it) if we made it,
            // now or on an earlier build. A file that was already there is only ever reported as updated.
            $created = ! $existed || ($previous->observer($path)['created'] ?? false);

            $this->writeObserver($tree, $observer, $path, $modelFqcn, $existed);

            $output[$created ? 'created' : 'updated'][] = $path;
            $next->recordObserver($path, $observer->name(), $created);

            if (config('blueprint_observers.attach_attribute', true)) {
                $modelPath = $this->attachObservedBy($observer, $modelFqcn, $previous, $next);

                if ($modelPath !== null) {
                    $output['updated'][] = $modelPath;
                }
            }
        }

        $this->manifests->save($next);

        return $output;
    }

    /** @return array<int, string> */
    public function types(): array
    {
        return ['observers'];
    }

    private function writeObserver(Tree $tree, Observer $observer, string $path, string $modelFqcn, bool $existed): void
    {
        $absolute = base_path($path);

        $source = $existed ? $this->files->get($absolute) : $this->newFile($observer);

        [$methods, $imports] = $this->methods($tree, $observer, $modelFqcn);

        $result = $this->editor->update(
            $source,
            $observer->name(),
            $modelFqcn,
            $observer->afterCommit(),
            $methods,
            $imports,
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
     * @return array{0: array<string,string>, 1: array<int,string>}  event => method source, extra imports
     */
    private function methods(Tree $tree, Observer $observer, string $modelFqcn): array
    {
        $modelClass = class_basename($modelFqcn);
        $variable = Str::camel($modelClass);
        $blueprintModel = $tree->modelForContext($observer->modelName());

        $methods = [];
        $imports = [];

        foreach ($observer->events() as $event => $snippets) {
            $rendered = $this->renderer->render($snippets, new SnippetContext(
                observer: $observer->name(),
                observerFqcn: $observer->fullyQualifiedClassName(),
                model: $modelClass,
                modelFqcn: $modelFqcn,
                variable: $variable,
                event: $event,
                blueprintModel: $blueprintModel,
            ));

            $methods[$event] = ObserverMethodBuilder::build($event, $modelClass, $variable, $rendered['body']);
            $imports = [...$imports, ...$rendered['imports']];
        }

        return [$methods, array_values(array_unique($imports))];
    }

    private function newFile(Observer $observer): string
    {
        return str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$observer->fullyQualifiedNamespace(), $observer->name()],
            $this->files->get($this->stubPath()),
        );
    }

    /**
     * Adds #[ObservedBy] to the model when its file exists. Returns the model path if it was modified.
     */
    private function attachObservedBy(Observer $observer, string $modelFqcn, Manifest $previous, Manifest $next): ?string
    {
        $modelPath = $this->relativePath($modelFqcn);
        $absolute = base_path($modelPath);

        if (! $this->files->exists($absolute)) {
            return null;
        }

        $observerFqcn = $observer->fullyQualifiedClassName();
        $modelClass = class_basename($modelFqcn);

        $result = $this->injector->inject($this->files->get($absolute), $modelClass, $observerFqcn);

        if ($result->changed()) {
            $this->files->put($absolute, $result->source);
            $next->recordModel($modelPath, $observerFqcn, $modelClass, $result->addedImports);

            return $modelPath;
        }

        // Already attached by an earlier build: keep tracking it so erase can still undo it.
        $earlier = $previous->model($modelPath, $observerFqcn);
        if ($earlier !== null) {
            $next->recordModel($modelPath, $observerFqcn, $earlier['model'], $earlier['imports']);
        }

        return null;
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
        $custom = base_path('stubs/blueprint/observer.stub');

        return $this->files->exists($custom) ? $custom : dirname(__DIR__, 2) . '/stubs/observer.stub';
    }
}
