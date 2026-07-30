<?php

namespace Devskio\StatamicOhdearHealthCheck;

use Devskio\StatamicOhdearHealthCheck\Checks\ForgottenFiles;
use Devskio\StatamicOhdearHealthCheck\Checks\StatamicVersion;
use Devskio\StatamicOhdearHealthCheck\Checks\StorageFolderSize;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\CP\Nav;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public function register(): void
    {
        parent::register();

        $configPath = __DIR__.'/../config/statamic-ohdear-addon.php';
        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, 'statamic-ohdear-addon');
        }

        $this->app->booting(function (): void {
            $this->synchronizeSharedHealthCheckConfig();
        });
    }

    public function boot(): void
    {
        parent::boot();

        // Register CP routes directly — bypasses getAddon() so routes always load
        // even when the Statamic addon manifest hasn't been rebuilt yet.
        $this->registerCpRoutes(__DIR__.'/../routes/cp.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'statamic-ohdear-addon');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/statamic-ohdear-addon.php' => config_path('statamic-ohdear-addon.php'),
            ], 'statamic-ohdear-addon-config');

            $this->publishes([
                __DIR__.'/../resources/blueprints' => resource_path('blueprints/vendor/statamic-ohdear-addon'),
            ], 'statamic-ohdear-addon-blueprints');
        }

        Nav::extend(function ($nav) {
            // Guard prevents a fatal crash on every CP page if the route
            // wasn't registered (e.g. missing addon manifest in Docker).
            if (! Route::has('statamic.cp.statamic-ohdear-addon.config')) {
                return;
            }

            $nav->create('OhDear Health Check')
                ->section('Tools')
                ->route('statamic-ohdear-addon.config')
                ->icon('pulse');
        });
    }

    protected function synchronizeSharedHealthCheckConfig(): void
    {
        $sharedConfig = config('ohdear-health-check', []);
        $addonConfig = config('statamic-ohdear-addon', []);
        $additionalChecks = config('ohdear-health-check.additional_checks', []);

        $path = Arr::get($addonConfig, 'health_route.path', Arr::get($sharedConfig, 'health_route.path', '/ohdear-health-check'));
        $middleware = Arr::get(
            $addonConfig,
            'health_route.middleware',
            Arr::get($sharedConfig, 'health_route.middleware', ['web', 'cache.headers:public;max_age=300;etag'])
        );

        if (! is_array($middleware)) {
            $middleware = [$middleware];
        }

        $secret = Arr::get($addonConfig, 'secret', Arr::get($addonConfig, 'oh_dear_secret_key', config('ohdear-health-check.secret')));

        config()->set('ohdear-health-check.health_route', [
            'path' => $path,
            'middleware' => array_values($middleware),
        ]);
        config()->set('ohdear-health-check.secret', $secret);
        config()->set('ohdear-health-check.response_format', Arr::get($addonConfig, 'response_format', 'ohdear'));

        foreach ([StorageFolderSize::class, StatamicVersion::class, ForgottenFiles::class] as $check) {
            if (! in_array($check, $additionalChecks, true)) {
                $additionalChecks[] = $check;
            }
        }

        config()->set('ohdear-health-check.additional_checks', $additionalChecks);
    }
}
