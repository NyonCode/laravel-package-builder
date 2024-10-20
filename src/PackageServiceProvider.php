<?php declare(strict_types=1);

namespace NyonCode\LaravelPackageBuilder;

use Exception;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use NyonCode\LaravelPackageBuilder\Exceptions\PackagerException;
use ReflectionClass;

abstract class PackageServiceProvider extends ServiceProvider
{
    /**
     * The separator used for tagging resources.
     *
     * @var string
     */
    public string $tagSeparator = '::';

    /**
     * Instance of the Packager class.
     *
     * @var Packager
     */
    protected Packager $packager;

    /**
     * Configure the packager instance.
     *
     * @param Packager $packager
     * @return void
     */
    abstract public function configure(Packager $packager): void;

    /**
     * Actions to perform before registering the package.
     *
     * @return void
     */
    public function registeringPackage(): void
    {
        // Define any actions to be performed before registering the package.
    }

    /**
     * Register the service provider.
     *
     * @return void
     * @throws Exception
     */
    public function register(): void
    {
        $this->registeringPackage();

        $this->packager = $this->bootPackager();
        $this->packager->hasBasePath($this->getPackageBaseDir());
        $this->configure($this->packager);

        if (empty($this->packager->name)) {
            throw PackagerException::invalidName();
        }

        $this->registerConfig();
        $this->registeredPackage();
    }

    /**
     * Actions to perform after registering the package.
     *
     * @return void
     */
    public function registeredPackage(): void
    {
        // Define any actions to be performed after registering the package.
    }

    /**
     * Actions to perform before booting the package.
     *
     * @return void
     */
    public function bootingPackage(): void
    {
        // Define any actions to be performed before booting the package.
    }

    /**
     * Boot the service provider.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->bootingPackage();

        if ($this->packager->isConfigurable) {
            $this->publishConfig();
        }

        if ($this->packager->isRoutable) {
            $this->loadRoutes();
        }

        if ($this->packager->isMigratable) {
            $this->loadMigrations();
            $this->publishMigrations();
        }

        if ($this->packager->isTranslatable) {
            $this->loadTranslations();

            if ($this->packager->loadJsonTranslate) {
                $this->loadJsonTranslations();
            }

            $this->publishTranslations();
        }

        if ($this->packager->isView) {
            $this->loadViews();
            $this->publishViews();
        }

        if ($this->packager->isViewComponent) {
            $this->loadViewComponents();
        }

        $this->bootedPackage();
    }

    /**
     * Actions to perform after booting the package.
     *
     * @return void
     */
    public function bootedPackage(): void
    {
        // Define any actions to be performed after booting the package.
    }

    /**
     * Create and return a new Packager instance.
     *
     * @return Packager
     */
    public function bootPackager(): Packager
    {
        return new Packager();
    }

    /**
     * Get the base directory of the package.
     *
     * @return string
     */
    public function getPackageBaseDir(): string
    {
        $reflector = new ReflectionClass(get_class($this));

        return dirname($reflector->getFileName());
    }

    /**
     * Register the package configuration files.
     *
     * @return void
     * @throws Exception
     */
    protected function registerConfig(): void
    {
        if (!empty($this->packager->configFiles())) {
            foreach ($this->packager->configFiles() as $configFile) {
                if (!is_array(value: require $configFile->dirname . DIRECTORY_SEPARATOR . $configFile->basename)) {
                    throw PackagerException::configMustReturnArray($configFile->basename);
                }

                $this->mergeConfigFrom(
                    path: $configFile->dirname . DIRECTORY_SEPARATOR . $configFile->basename,
                    key: $configFile->filename
                );
            }
        }
    }

    /**
     * Load the route files for the package.
     *
     * @return void
     */
    protected function loadRoutes(): void
    {
        foreach ($this->packager->routeFiles() as $routeFile) {
            $this->loadRoutesFrom(
                path: $routeFile->dirname . DIRECTORY_SEPARATOR . $routeFile->basename
            );
        }
    }

    /**
     * Load the migration files for the package.
     *
     * @return void
     */
    protected function loadMigrations(): void
    {
        foreach ($this->packager->migrationFiles() as $migrationFile) {
            $this->loadMigrationsFrom($migrationFile->dirname . DIRECTORY_SEPARATOR . $migrationFile->basename);
        }
    }

    /**
     * Load the translation files for the package.
     *
     * @return void
     */
    protected function loadTranslations(): void
    {
        $this->loadTranslationsFrom($this->packager->translationPath(), $this->packager->shortName());
    }

    /**
     * Load the JSON translation files for the package.
     *
     * @return void
     */
    protected function loadJsonTranslations(): void
    {
        $this->loadJsonTranslationsFrom(path: $this->packager->translationPath());
    }

    protected function loadViews(): void
    {
        $this->loadViewsFrom(path: $this->packager->views(), namespace: $this->packager->shortName());
    }

    protected function loadViewComponents(): void
    {
        collect($this->packager->viewComponents())->each(function ($component, $name) {
            Blade::component(class: $name, alias: $component);
        });
    }

    /**
     * Publish the package configuration files.
     *
     * @return void
     */
    private function publishConfig(): void
    {
        foreach ($this->packager->configFiles() as $configFile) {
            $this->publishes(
                paths: [$configFile->dirname . DIRECTORY_SEPARATOR . $configFile->basename => config_path($configFile->basename)],
                groups: "{$this->packager->shortName()}::config"
            );
        }
    }

    /**
     * Publish the migration files for the package.
     *
     * @return void
     */
    protected function publishMigrations(): void
    {
        $this->publishesMigrations(
            paths: [(collect($this->packager->migrationFiles())->first())->dirname => database_path('migrations')],
            groups: $this->publishTagFormat('migrations')
        );
    }

    protected function publishTranslations(): void
    {
        $this->publishes(
            paths: [$this->packager->translationPath() => lang_path("vendor/{$this->packager->shortName()}")],
            groups: $this->publishTagFormat('translations')
        );
    }

    protected function publishViews(): void
    {
        $this->publishes(
            paths: [$this->packager->views() => resource_path("views/vendor/{$this->packager->shortName()}")],
            groups: $this->publishTagFormat("views")
        );
    }

    /**
     * Get the tag separator for publishing.
     *
     * @return string
     */
    public function tagSeparator(): string
    {
        return $this->tagSeparator;
    }

    /**
     * Format the publishing tag for a given group.
     *
     * @param string $groupName
     * @return string
     */
    public function publishTagFormat(string $groupName): string
    {
        return $this->packager->shortName() . $this->tagSeparator() . $groupName;
    }
}