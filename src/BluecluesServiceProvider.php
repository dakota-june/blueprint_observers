<?php

declare(strict_types=1);

namespace Dakotajune\Blueclues;

use Blueprint\Blueprint;
use Dakotajune\Blueclues\Generators\ObserverGenerator;
use Dakotajune\Blueclues\Lexers\ObserverLexer;
use Dakotajune\Blueclues\Listeners\RestoreModelsOnErase;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\ServiceProvider;

/**
 * Deliberately NOT a DeferrableProvider: Blueprint's own provider already defers Blueprint::class, and
 * two deferred providers cannot both own one service. We also need boot() to run so the erase listener
 * is registered before `blueprint:erase` starts.
 */
final class BluecluesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/blueclues.php', 'blueclues');

        // extend() runs when Blueprint is resolved, whatever order the providers loaded in.
        $this->app->extend(Blueprint::class, function (Blueprint $blueprint, $app): Blueprint {
            $blueprint->registerLexer($app->make(ObserverLexer::class));
            $blueprint->registerGenerator($app->make(ObserverGenerator::class));

            return $blueprint;
        });
    }

    public function boot(): void
    {
        $this->app['events']->listen(CommandStarting::class, RestoreModelsOnErase::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/blueclues.php' => config_path('blueclues.php'),
            ], 'blueclues-config');

            $this->publishes([
                __DIR__ . '/../stubs/observer.stub' => base_path('stubs/blueprint/observer.stub'),
            ], 'blueclues-stubs');
        }
    }
}
