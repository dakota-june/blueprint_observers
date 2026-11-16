<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Support;

/**
 * Remembers what blueprint_observers did to files it does not wholly own, so `blueprint:erase` can undo it safely.
 *
 *  models:     model file => observer FQCN => { model, imports }   #[ObservedBy] edits made to a model
 *  observers:  observer file => { class, created }                 observer files; `created` = we created the file
 *  nova:
 *    observers: observer file => { class, created }                observers from the `nova_observers:` block
 *    providers: provider file => { class, imports }                Nova providers that got a blueprintObservers()
 *
 * Like `.blueprint`, it describes the latest build only. The regular and Nova generators run one after the
 * other and share this file, so each carries the other's sections over untouched.
 */
final class Manifest
{
    /**
     * @param  array<string, array<string, array{model:string, imports:array<int,string>}>>  $models
     * @param  array<string, array{class:string, created:bool}>  $observers
     * @param  array<string, array{class:string, created:bool}>  $novaObservers
     * @param  array<string, array{class:string, imports:array<int,string>}>  $providers
     */
    public function __construct(
        private array $models = [],
        private array $observers = [],
        private array $novaObservers = [],
        private array $providers = [],
    ) {}

    public static function fromJson(?string $json): self
    {
        if ($json === null || trim($json) === '') {
            return new self();
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return new self();
        }

        $nova = is_array($decoded['nova'] ?? null) ? $decoded['nova'] : [];

        return new self(
            is_array($decoded['models'] ?? null) ? $decoded['models'] : [],
            is_array($decoded['observers'] ?? null) ? $decoded['observers'] : [],
            is_array($nova['observers'] ?? null) ? $nova['observers'] : [],
            is_array($nova['providers'] ?? null) ? $nova['providers'] : [],
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

    public function recordNovaObserver(string $path, string $class, bool $created): void
    {
        $this->novaObservers[$path] = ['class' => $class, 'created' => $created];
    }

    /** @return array{class:string, created:bool}|null */
    public function novaObserver(string $path): ?array
    {
        return $this->novaObservers[$path] ?? null;
    }

    /** @return array<string, array{class:string, created:bool}> */
    public function novaObservers(): array
    {
        return $this->novaObservers;
    }

    /** @param  array<int, string>  $imports  Imports blueprint_observers added to the provider. */
    public function recordProvider(string $path, string $class, array $imports): void
    {
        $this->providers[$path] = ['class' => $class, 'imports' => array_values($imports)];
    }

    /** @return array{class:string, imports:array<int,string>}|null */
    public function provider(string $path): ?array
    {
        return $this->providers[$path] ?? null;
    }

    /** @return array<string, array{class:string, imports:array<int,string>}> */
    public function providers(): array
    {
        return $this->providers;
    }

    /** Copies the Nova sections of $other into this manifest (used by the regular generator). */
    public function carryNovaFrom(self $other): self
    {
        $this->novaObservers = $other->novaObservers;
        $this->providers = $other->providers;

        return $this;
    }

    /** A copy without the Nova sections (used by the Nova generator before it records its own). */
    public function withoutNova(): self
    {
        return new self($this->models, $this->observers);
    }

    public function isEmpty(): bool
    {
        return $this->models === [] && $this->observers === [] && $this->novaObservers === [] && $this->providers === [];
    }

    public function toJson(): string
    {
        $data = ['models' => $this->models, 'observers' => $this->observers];

        if ($this->novaObservers !== [] || $this->providers !== []) {
            $data['nova'] = ['observers' => $this->novaObservers, 'providers' => $this->providers];
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
