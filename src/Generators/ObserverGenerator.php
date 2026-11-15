<?php

declare(strict_types=1);

namespace Dakotajune\Blueclues\Generators;

use Blueprint\Blueprint;
use Blueprint\Contracts\Generator;
use Blueprint\Tree;
use Dakotajune\Blueclues\Models\Observer;
use Dakotajune\Blueclues\Support\Manifest;
use Dakotajune\Blueclues\Support\ManifestStore;
use Dakotajune\Blueclues\Support\ObservedByInjector;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class ObserverGenerator implements Generator
{
    private readonly ObservedByInjector $injector;

    private readonly ManifestStore $manifests;

    // The Generator contract fixes the first parameter; the rest are optional collaborators.
    public function __construct(
        private readonly Filesystem $files,
        ?ObservedByInjector $injector = null,
        ?ManifestStore $manifests = null,
    ) {
        $this->injector = $injector ?? new ObservedByInjector();
        $this->manifests = $manifests ?? new ManifestStore($files);
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

        $stub = $this->files->get($this->stubPath());
        $previous = $this->manifests->load();
        $next = new Manifest();

        foreach ($observers as $observer) {
            $path = $this->relativePath($observer->fullyQualifiedClassName());
            $modelFqcn = $tree->fqcnForContext($observer->modelName());

            $this->files->ensureDirectoryExists(dirname(base_path($path)));
            $this->files->put(base_path($path), $this->populateStub($stub, $observer, $modelFqcn));
            $output['created'][] = $path;

            if (config('blueclues.attach_attribute', true)) {
                $modelPath = $this->attachObservedBy($observer, $modelFqcn, $previous, $next);

                if ($modelPath !== null) {
                    $output['updated'][] = $modelPath;
                }
            }
        }

        // Like `.blueprint`, the manifest describes the latest build only.
        $this->manifests->save($next);

        return $output;
    }

    /** @return array<int, string> */
    public function types(): array
    {
        return ['observers'];
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
            $next->record($modelPath, $observerFqcn, $modelClass, $result->addedImports);

            return $modelPath;
        }

        // Already attached by an earlier build: keep tracking it so erase can still undo it.
        $earlier = $previous->get($modelPath, $observerFqcn);
        if ($earlier !== null) {
            $next->record($modelPath, $observerFqcn, $earlier['model'], $earlier['imports']);
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

    private function populateStub(string $stub, Observer $observer, string $modelFqcn): string
    {
        $modelClass = class_basename($modelFqcn);
        $variable = Str::camel($modelClass);

        $imports = [];
        if ($this->namespaceOf($modelFqcn) !== $observer->fullyQualifiedNamespace()) {
            $imports[] = "use {$modelFqcn};";
        }

        $implements = '';
        if ($observer->afterCommit()) {
            $imports[] = 'use Illuminate\\Contracts\\Events\\ShouldHandleEventsAfterCommit;';
            $implements = ' implements ShouldHandleEventsAfterCommit';
        }

        sort($imports);

        $methods = array_map(
            fn (string $event): string => $this->eventMethod($event, $modelClass, $variable),
            $observer->events(),
        );

        return str_replace(
            ['{{ namespace }}', '{{ imports }}', '{{ class }}', '{{ implements }}', '{{ methods }}'],
            [$observer->fullyQualifiedNamespace(), implode("\n", $imports), $observer->name(), $implements, implode("\n\n", $methods)],
            $stub,
        );
    }

    private function eventMethod(string $event, string $modelClass, string $variable): string
    {
        return implode("\n", [
            '    /**',
            "     * Handle the {$modelClass} \"{$event}\" event.",
            '     */',
            "    public function {$event}({$modelClass} \${$variable}): void",
            '    {',
            '        //',
            '    }',
        ]);
    }

    private function namespaceOf(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? '' : substr($fqcn, 0, $pos);
    }
}
