<?php

declare(strict_types=1);

namespace Dakotajune\Blueclues\Support;

/**
 * Remembers which existing model files blueclues modified, so `blueprint:erase` can undo it.
 *
 * Shape: [ 'app/Models/Post.php' => [ 'App\\Observers\\PostObserver' => ['model' => 'Post', 'imports' => [...]] ] ]
 */
final class Manifest
{
    /** @param  array<string, array<string, array{model:string, imports:array<int,string>}>>  $entries */
    public function __construct(private array $entries = []) {}

    public static function fromJson(?string $json): self
    {
        if ($json === null || trim($json) === '') {
            return new self();
        }

        $decoded = json_decode($json, true);

        return new self(is_array($decoded) ? $decoded : []);
    }

    /** @param  array<int, string>  $imports */
    public function record(string $modelPath, string $observerFqcn, string $modelClass, array $imports): void
    {
        $this->entries[$modelPath][$observerFqcn] = [
            'model' => $modelClass,
            'imports' => array_values($imports),
        ];
    }

    /** @return array{model:string, imports:array<int,string>}|null */
    public function get(string $modelPath, string $observerFqcn): ?array
    {
        return $this->entries[$modelPath][$observerFqcn] ?? null;
    }

    /** @return array<string, array<string, array{model:string, imports:array<int,string>}>> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function toJson(): string
    {
        return json_encode($this->entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
