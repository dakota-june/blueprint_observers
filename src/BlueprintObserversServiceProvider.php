<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers;

use Blueprint\Blueprint;
use Dakotajune\BlueprintObservers\Generators\ObserverGenerator;
use Dakotajune\BlueprintObservers\Lexers\ObserverLexer;
use Dakotajune\BlueprintObservers\Listeners\CleanUpOnErase;
use Dakotajune\BlueprintObservers\Snippets\SnippetRegistry;
use Dakotajune\BlueprintObservers\Support\NoticeBag;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\ServiceProvider;

/**
 * Deliberately NOT a DeferrableProvider: Blueprint's own provider already defers Blueprint::class, and
 * two deferred providers cannot both own one service. We also need boot() to run so the erase listener
 * is registered before `blueprint:erase` starts.
 *
 * Other packages add snippets from their own provider:
 *
 *     $this->app->make(\Dakotajune\BlueprintObservers\Snippets\SnippetRegistry::class)->register(new MySnippet());
 */
final class BlueprintObserversServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/blueprint_observers.php', 'blueprint_observers');

        $this->app->singleton(SnippetRegistry::class);
        $this->app->singleton(NoticeBag::class);

        // extend() runs when Blueprint is resolved, whatever order the providers loaded in.
        $this->app->extend(Blueprint::class, function (Blueprint $blueprint, $app): Blueprint {
            $blueprint->registerLexer($app->make(ObserverLexer::class));
            $blueprint->registerGenerator($app->make(ObserverGenerator::class));

            return $blueprint;
        });
    }

    public function boot(): void
    {
        $events = $this->app['events'];

        // Resolve the listener only when erase really starts, not for every artisan command.
        $events->listen(CommandStarting::class, function (CommandStarting $event): void {
            if ($event->command === 'blueprint:erase') {
                $this->app->make(CleanUpOnErase::class)->handle($event);
            }
        });
        $events->listen(CommandFinished::class, function (CommandFinished $event): void {
            if (! in_array($event->command, ['blueprint:build', 'blueprint:erase'], true)) {
                return;
            }

            foreach ($this->app->make(NoticeBag::class)->pull() as $notice) {
                $event->output->writeln("<comment>blueprint_observers:</comment> {$notice}");
            }
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/blueprint_observers.php' => config_path('blueprint_observers.php'),
            ], 'blueprint-observers-config');

            $this->publishes([
                __DIR__ . '/../stubs/observer.stub' => base_path('stubs/blueprint/observer.stub'),
            ], 'blueprint-observers-stubs');
        }
    }
}
