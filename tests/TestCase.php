<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Tests;

use Blueprint\BlueprintServiceProvider;
use Dakotajune\BlueprintObservers\BlueprintObserversServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $project;

    private string $originalCwd;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd();
        $this->project = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blueprint_observers-' . bin2hex(random_bytes(4));
        (new Filesystem())->ensureDirectoryExists($this->project . '/app/Models');

        parent::setUp();

        $this->app->setBasePath($this->project);

        // Blueprint's own generators write relative to the working directory, ours to base_path().
        // In a real app these are the same place; make them the same here so nothing leaks into the package.
        chdir($this->project);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);   // Windows cannot delete the current directory

        if (getenv('BLUEPRINT_OBSERVERS_KEEP') === false) {
            (new Filesystem())->deleteDirectory($this->project);
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [BlueprintServiceProvider::class, BlueprintObserversServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('blueprint.namespace', 'App');
        $app['config']->set('blueprint.models_namespace', 'Models');
        $app['config']->set('blueprint.app_path', 'app');
    }

    protected function writeModel(string $name, string $extra = ''): void
    {
        file_put_contents("{$this->project}/app/Models/{$name}.php", <<<PHP
        <?php

        namespace App\\Models;

        use Illuminate\\Database\\Eloquent\\Model;
        {$extra}
        class {$name} extends Model
        {
        }

        PHP);
    }
}