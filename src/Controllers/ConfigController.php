<?php

namespace Devskio\StatamicOhdearHealthCheck\Controllers;

use Devskio\LaravelOhdearHealthCheck\Health\Checks\DatabaseCheck;
use Devskio\LaravelOhdearHealthCheck\Health\Checks\ErrorLogCheck;
use Devskio\LaravelOhdearHealthCheck\Health\Checks\UsedDiskSpaceCheck;
use Devskio\StatamicOhdearHealthCheck\ServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Statamic\Facades\Blueprint;
use Statamic\Facades\User;

class ConfigController
{
    public function index()
    {
        $this->authorize();

        $blueprint = Blueprint::find('statamic-ohdear-health-check::config');

        abort_unless($blueprint !== null, 404);

        $fields = $blueprint
            ->fields()
            ->addValues($this->currentValues())
            ->preProcess();

        return Inertia::render('PublishForm', [
            'title'        => 'OhDear Health Check',
            'blueprint'    => $blueprint->toPublishArray(),
            'values'       => $fields->values(),
            'meta'         => $fields->meta(),
            'submitUrl'    => cp_route('statamic-ohdear-health-check.config.save'),
            'submitMethod' => 'post',
            'asConfig'     => true,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $this->authorize();

        $blueprint = Blueprint::find('statamic-ohdear-health-check::config');

        abort_unless($blueprint !== null, 404);

        $fields = $blueprint
            ->fields()
            ->addValues($request->all());

        $fields->validate();

        $values = $fields->process()->values()->all();

        $this->writeConfig(config_path('ohdear-health-check.php'), $this->sharedConfig($values));
        $this->writeConfig(config_path('statamic-ohdear-health-check.php'), $this->statamicConfig($values));

        return response()->json(['saved' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function currentValues(): array
    {
        $sharedChecks = config('ohdear-health-check.checks', []);
        $sharedRoute = config('ohdear-health-check.health_route', []);
        $statamicConfig = config('statamic-ohdear-health-check', []);

        $diskCheck = $this->findCheck($sharedChecks, UsedDiskSpaceCheck::class);
        $errorLogCheck = $this->findCheck($sharedChecks, ErrorLogCheck::class);

        return [
            'health_check_path' => Arr::get($sharedRoute, 'path', Arr::get($statamicConfig, 'health_route.path', '/ohdear-health-check')),
            'oh_dear_secret_key' => (string) (config('ohdear-health-check.secret')
                ?? Arr::get($statamicConfig, 'secret')
                ?? Arr::get($statamicConfig, 'oh_dear_secret_key', '')),
            'oh_dear_api_token' => (string) Arr::get($statamicConfig, 'api_token', ''),
            'oh_dear_monitor_id' => (string) (Arr::get($statamicConfig, 'monitor_id') ?? ''),
            'enable_database_check' => $this->hasCheck($sharedChecks, DatabaseCheck::class),
            'enable_disk_used_space_check' => $this->hasCheck($sharedChecks, UsedDiskSpaceCheck::class),
            'disk_space_error_threshold' => (float) Arr::get(
                $diskCheck,
                'options.error_threshold_percentage',
                Arr::get($statamicConfig, 'disk_space_error_threshold', 90)
            ),
            'disk_space_warning_threshold' => (float) Arr::get(
                $diskCheck,
                'options.warning_threshold_percentage',
                Arr::get($statamicConfig, 'disk_space_warning_threshold', 70)
            ),
            'enable_error_log_size_check' => $this->hasCheck($sharedChecks, ErrorLogCheck::class),
            'error_log_error_threshold' => (float) Arr::get(
                $errorLogCheck,
                'options.max_log_size_mb',
                Arr::get($statamicConfig, 'error_log_error_threshold', 500)
            ),
            'error_log_warning_threshold' => (float) Arr::get(
                $errorLogCheck,
                'options.warning_log_size_mb',
                Arr::get($statamicConfig, 'error_log_warning_threshold', 50)
            ),
            'enable_storage_folder_size_check' => (bool) Arr::get($statamicConfig, 'enable_storage_folder_size_check', true),
            'storage_folder_size_error_threshold' => (float) Arr::get($statamicConfig, 'storage_folder_size_error_threshold', 500),
            'storage_folder_size_warning_threshold' => (float) Arr::get($statamicConfig, 'storage_folder_size_warning_threshold', 50),
            'enable_statamic_version_check' => (bool) Arr::get($statamicConfig, 'enable_statamic_version_check', true),
            'enable_forgotten_files_check' => (bool) Arr::get($statamicConfig, 'enable_forgotten_files_check', true),
            'allowed_files' => Arr::get($statamicConfig, 'allowed_files', []),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    protected function sharedConfig(array $values): array
    {
        $defaultMiddleware = config('statamic-ohdear-health-check.health_route.middleware', ['web', 'cache.headers:public;max_age=300;etag']);

        return [
            'health_route' => [
                'path' => $this->normalizePath($values['health_check_path'] ?? '/ohdear-health-check'),
                'middleware' => is_array($defaultMiddleware) ? array_values($defaultMiddleware) : [$defaultMiddleware],
            ],
            'secret' => $this->nullableString($values['oh_dear_secret_key'] ?? null),
            'response_format' => 'ohdear',
            'checks' => array_values(array_filter([
                $this->boolean($values['enable_database_check'] ?? false) ? DatabaseCheck::class : null,
                $this->boolean($values['enable_disk_used_space_check'] ?? false) ? [
                    'class' => UsedDiskSpaceCheck::class,
                    'options' => [
                        'warning_threshold_percentage' => (float) ($values['disk_space_warning_threshold'] ?? 70),
                        'error_threshold_percentage' => (float) ($values['disk_space_error_threshold'] ?? 90),
                    ],
                ] : null,
                $this->boolean($values['enable_error_log_size_check'] ?? false) ? [
                    'class' => ErrorLogCheck::class,
                    'options' => [
                        'warning_log_size_mb' => (int) ($values['error_log_warning_threshold'] ?? 50),
                        'max_log_size_mb' => (int) ($values['error_log_error_threshold'] ?? 500),
                    ],
                ] : null,
            ])),
            'additional_checks' => config('ohdear-health-check.additional_checks', []),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    protected function statamicConfig(array $values): array
    {
        $middleware = config('statamic-ohdear-health-check.health_route.middleware', ['web', 'cache.headers:public;max_age=300;etag']);

        return [
            'health_route' => [
                'path' => $this->normalizePath($values['health_check_path'] ?? '/ohdear-health-check'),
                'middleware' => is_array($middleware) ? array_values($middleware) : [$middleware],
            ],
            'response_format' => 'ohdear',
            'secret' => $this->nullableString($values['oh_dear_secret_key'] ?? null),
            'oh_dear_secret_key' => $this->nullableString($values['oh_dear_secret_key'] ?? null),
            'api_token' => $this->nullableString($values['oh_dear_api_token'] ?? null),
            'monitor_id' => $this->nullableInteger($values['oh_dear_monitor_id'] ?? null),
            'api_base_url' => config('statamic-ohdear-health-check.api_base_url', 'https://ohdear.app/api'),
            'api_timeout' => (int) config('statamic-ohdear-health-check.api_timeout', 5),
            'enable_database_check' => $this->boolean($values['enable_database_check'] ?? false),
            'enable_disk_used_space_check' => $this->boolean($values['enable_disk_used_space_check'] ?? false),
            'disk_space_error_threshold' => (float) ($values['disk_space_error_threshold'] ?? 90),
            'disk_space_warning_threshold' => (float) ($values['disk_space_warning_threshold'] ?? 70),
            'enable_error_log_size_check' => $this->boolean($values['enable_error_log_size_check'] ?? false),
            'error_log_error_threshold' => (float) ($values['error_log_error_threshold'] ?? 500),
            'error_log_warning_threshold' => (float) ($values['error_log_warning_threshold'] ?? 50),
            'enable_storage_folder_size_check' => $this->boolean($values['enable_storage_folder_size_check'] ?? false),
            'storage_folder_size_error_threshold' => (float) ($values['storage_folder_size_error_threshold'] ?? 500),
            'storage_folder_size_warning_threshold' => (float) ($values['storage_folder_size_warning_threshold'] ?? 50),
            'enable_statamic_version_check' => $this->boolean($values['enable_statamic_version_check'] ?? false),
            'enable_forgotten_files_check' => $this->boolean($values['enable_forgotten_files_check'] ?? false),
            'allowed_files' => array_values(array_filter(array_map(
                static fn (mixed $file): string => trim((string) $file),
                is_array($values['allowed_files'] ?? null) ? $values['allowed_files'] : []
            ))),
        ];
    }

    /**
     * @param array<int, mixed> $checks
     * @return array{class: string, options: array<string, mixed>}|null
     */
    protected function findCheck(array $checks, string $class): ?array
    {
        foreach ($checks as $check) {
            if ($check === $class) {
                return ['class' => $class, 'options' => []];
            }

            if (is_array($check) && Arr::get($check, 'class') === $class) {
                return [
                    'class' => $class,
                    'options' => Arr::get($check, 'options', []),
                ];
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $checks
     */
    protected function hasCheck(array $checks, string $class): bool
    {
        return $this->findCheck($checks, $class) !== null;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function writeConfig(string $path, array $config): void
    {
        File::put($path, "<?php\n\nreturn ".var_export($config, true).";\n");
    }

    protected function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    protected function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function normalizePath(mixed $value): string
    {
        $path = trim((string) $value);

        if ($path === '') {
            return '/ohdear-health-check';
        }

        return '/'.ltrim($path, '/');
    }

    protected function authorize(): void
    {
        abort_unless(User::current()?->can(ServiceProvider::PERMISSION), 403);
    }
}
