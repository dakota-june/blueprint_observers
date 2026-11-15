<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Support;

/**
 * Remembers what blueprint_observers did to files it does not wholly own, so `blueprint:erase` can undo it safely.
 *
 *  models:    model file => observer FQCN => { model, imports }   #[ObservedBy] edits made to a model
 *  observers: observer file => { class, created }                observer files; `created` = we created the file
 *
 * Like `.blueprint`, it describes the latest build only.
 */
final class Manifest
{
    /**
     * @param  array<string, array<string, array{model:string, imports:array<int,string>}>>  $models
     * @param  array<string, array{class:string, created:bool}>  $observers
     */
    public function __construct(private array $models = [], private array $observers = []) {}

    public static function fromJson(?string $json): self
    {
        if ($json === null || trim($json) === '') {
            return new self();
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return new self();
        }

        return new self(
            is_array($decoded['models'] ?? null) ? $decoded['models'] : [],
            is_array($decoded['observers'] ?? null) ? $decoded['observers'] : [],
        );
    }

    /** @param  array<int, string>  $imports */
    public function recordModel(string $modelPath, string $observerFqcn, string $modelClass, array $imports): void
    {
        $this->models[$modelPath][$observerFqcn] = ['model' => $modelClass, 'imports' => array_values($imports)];
    }

    /** @return array{model:string, imports:array<int,string>}|null */
    public function model(string $modelPath, string $observerFqcn): ?array
    {
        return $this->models[$modelPath][$observerFqcn] ?? null;
    }

    /** @return array<string, array<string, array{model:string, imports:array<int,string>}>> */
    public function models(): array
    {
        return $this->models;
    }

    public function recordObserver(string $path, string $class, bool $created): void
    {
        $this->observers[$path] = ['class' => $class, 'created' => $created];
    }

    /** @return array{class:string, created:bool}|null */
    public function observer(string $path): ?array
    {
        return $this->observers[$path] ?? null;
    }

    /** @return array<string, array{class:string, created:bool}> */
    public function observers(): array
    {
        return $this->observers;
    }

    public function isEmpty(): bool
    {
        return $this->models === [] && $this->observers === [];
    }

    public function toJson(): string
    {
        return json_encode(
            ['models' => $this->models, 'observers' => $this->observers],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . "\n";
    }
}
