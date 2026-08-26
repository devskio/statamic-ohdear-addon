<?php

namespace Devskio\StatamicOhdearHealthCheck;

use Devskio\StatamicOhdearHealthCheck\Checks\ForgottenFiles;
use Devskio\StatamicOhdearHealthCheck\Checks\StatamicVersion;
use Devskio\StatamicOhdearHealthCheck\Checks\StorageFolderSize;
use Illuminate\Support\Arr;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public const PERMISSION = 'configure ohdear health check';

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/statamic-ohdear-health-check.php', 'statamic-ohdear-health-check');

        $this->app->booting(function (): void {
            $this->synchronizeSharedHealthCheckConfig();
        });
    }

    public function boot(): void
    {
        parent::boot();

        Permission::extend(function () {
            Permission::register(self::PERMISSION)
                ->label('Configure OhDear Health Check');
        });

        Nav::extend(function ($nav) {
            $nav->create('OhDear Health Check')
                ->section('Tools')
                ->route('statamic-ohdear-health-check.config')
                ->icon('pulse')
                ->can(self::PERMISSION);
        });
    }

    protected function synchronizeSharedHealthCheckConfig(): void
    {
        $sharedConfig = config('ohdear-health-check', []);
        $addonConfig = config('statamic-ohdear-health-check', []);
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
