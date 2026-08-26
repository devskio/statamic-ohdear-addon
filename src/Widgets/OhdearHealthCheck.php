<?php

namespace Devskio\StatamicOhdearHealthCheck\Widgets;

use Devskio\LaravelOhdearHealthCheck\Core\CheckRunner;
use Devskio\StatamicOhdearHealthCheck\ServiceProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Statamic\Facades\User;
use Statamic\Widgets\Widget;

class OhdearHealthCheck extends Widget
{
    public const CACHE_KEY = 'statamic-ohdear-health-check.widget.v4';

    protected static $title = 'Oh Dear';

    /**
     * @var array<string, array{needles: array<int, string>, tab: string, label: string}>
     */
    protected array $applicationHealthChecks = [
        'disk' => [
            'needles' => ['UsedDiskSpaceCheck', 'Used Disk Space', 'UsedDiskSpace'],
            'tab' => 'check_disk_space',
            'label' => 'Disk space',
        ],
        'error_log' => [
            'needles' => ['ErrorLogCheck', 'Error Log', 'ErrorLog'],
            'tab' => 'check_error_log',
            'label' => 'Error log',
        ],
        'storage' => [
            'needles' => ['StorageFolderSize', 'Storage Folder Size', 'storageFolderSize'],
            'tab' => 'check_storage_size',
            'label' => 'Storage size',
        ],
        'forgotten' => [
            'needles' => ['ForgottenFiles', 'Forgotten Files', 'forgottenFiles'],
            'tab' => 'check_forgotten_files',
            'label' => 'Forgotten files',
        ],
    ];

    /**
     * @var array<string, array{needles: array<int, string>, tab: string}>
     */
    protected array $knownTabs = [
        'database' => [
            'needles' => ['DatabaseCheck', 'Database'],
            'tab' => 'general',
        ],
        'statamic_version' => [
            'needles' => ['StatamicVersion', 'Statamic Version'],
            'tab' => 'general',
        ],
    ];

    public function html()
    {
        if (! User::current()?->can(ServiceProvider::PERMISSION)) {
            return;
        }

        $ttl = max(0, (int) $this->config('cache', 60));
        $payload = $ttl > 0
            ? Cache::remember(self::CACHE_KEY, $ttl, fn () => $this->runChecks())
            : $this->runChecks();

        $checks = collect($payload['checks'] ?? []);
        $status = $this->normalizeStatus((string) ($payload['status'] ?? 'failed'));
        $configUrl = cp_route('statamic-ohdear-health-check.config');

        $passing = $checks->filter(fn (array $c) => $this->normalizeStatus((string) ($c['status'] ?? '')) === 'ok')->count();
        $warning = $checks->filter(fn (array $c) => $this->normalizeStatus((string) ($c['status'] ?? '')) === 'warning')->count();
        $failed = $checks->filter(fn (array $c) => $this->normalizeStatus((string) ($c['status'] ?? '')) === 'failed')->count();
        $total = $checks->reject(fn (array $c) => $this->normalizeStatus((string) ($c['status'] ?? '')) === 'skipped')->count();

        return view('statamic-ohdear-health-check::widgets.health-check', [
            'site' => $this->siteLabel(),
            'status' => $status,
            'headline' => $this->headline($status, $checks),
            'passing' => $passing,
            'total' => max($total, 1),
            'warningCount' => $warning,
            'failedCount' => $failed,
            'finishedAt' => $payload['finished_at'] ?? null,
            'monitors' => $this->monitors($checks, $configUrl),
            'applicationHealth' => $this->applicationHealth($checks, $configUrl),
            'configUrl' => $configUrl,
            'reportUrl' => $this->reportUrl(),
            'refreshUrl' => cp_route('statamic-ohdear-health-check.widget.refresh'),
            'cacheSeconds' => $ttl,
        ]);
    }

    /**
     * @return array{status: string, finished_at: string, checks: array<int, array<string, mixed>>}
     */
    protected function runChecks(): array
    {
        return app(CheckRunner::class)->run(array_merge(
            config('ohdear-health-check.checks', []),
            config('ohdear-health-check.additional_checks', []),
        ))->toArray();
    }

    protected function siteLabel(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: (string) config('app.name', 'Site');
    }

    protected function reportUrl(): string
    {
        $path = (string) config(
            'ohdear-health-check.health_route.path',
            config('statamic-ohdear-health-check.health_route.path', '/ohdear-health-check')
        );
        $url = url('/'.ltrim($path, '/'));
        $secret = config('ohdear-health-check.secret')
            ?? config('statamic-ohdear-health-check.secret')
            ?? config('statamic-ohdear-health-check.oh_dear_secret_key');

        if (is_string($secret) && $secret !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?').'secret='.urlencode($secret);
        }

        return $url;
    }

    protected function normalizeStatus(string $status): string
    {
        return match (strtolower($status)) {
            'ok', 'healthy', 'success' => 'ok',
            'warning', 'warn' => 'warning',
            'skipped', 'skip' => 'skipped',
            default => 'failed',
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $checks
     */
    protected function headline(string $status, Collection $checks): string
    {
        if ($status === 'ok') {
            return 'All checks passing';
        }

        $problem = $checks->first(
            fn (array $check) => $this->normalizeStatus((string) ($check['status'] ?? '')) === $status
        );

        if (! $problem) {
            return $status === 'warning' ? 'Attention needed' : 'Checks failing';
        }

        $message = trim((string) ($problem['notification_message'] ?? ''));

        return $message !== ''
            ? Str::limit($message, 72)
            : (($problem['label'] ?? $problem['name'] ?? 'Check').($status === 'warning' ? ' warning' : ' failed'));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $checks
     * @return array<int, array<string, mixed>>
     */
    protected function monitors(Collection $checks, string $configUrl): array
    {
        return $checks->map(function (array $check) use ($configUrl) {
            $key = $this->matchKey($check);
            $status = $this->normalizeStatus((string) ($check['status'] ?? 'failed'));
            $summary = $this->summary($check);
            [$value, $note] = $this->monitorDisplay($key, $check, $summary, $status);

            return [
                'name' => $check['label'] ?? $check['name'] ?? 'Check',
                'value' => $value,
                'note' => $note,
                'bad' => in_array($status, ['failed', 'warning'], true),
                'url' => $configUrl.'#'.$this->tabFor($key),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $checks
     * @return array<int, array<string, mixed>>
     */
    protected function applicationHealth(Collection $checks, string $configUrl): array
    {
        return collect($this->applicationHealthChecks)->map(function (array $definition, string $key) use ($checks, $configUrl) {
            $check = $this->findCheck($checks, $definition['needles']);
            $status = $this->normalizeStatus((string) ($check['status'] ?? 'skipped'));
            $summary = $check ? $this->summary($check) : 'not configured';
            $card = $this->applicationCard($key, $check, $summary, $status);

            return array_merge($card, [
                'key' => $key,
                'label' => $definition['label'],
                'status' => $status,
                'url' => $configUrl.'#'.$definition['tab'],
                'bad' => in_array($status, ['failed', 'warning'], true),
            ]);
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>|null  $check
     * @return array<string, mixed>
     */
    protected function applicationCard(string $key, ?array $check, string $summary, string $status): array
    {
        return match ($key) {
            'disk' => $this->diskCard($check, $summary),
            'error_log' => $this->errorLogCard($check, $summary),
            'storage' => $this->storageCard($check, $summary),
            'forgotten' => $this->forgottenCard($check, $summary),
            default => [
                'value' => $summary !== '' ? Str::limit($summary, 18) : '—',
                'valueSuffix' => null,
                'detail' => $summary,
                'items' => [],
                'bar' => null,
                'threshold' => null,
                'linkLabel' => null,
            ],
        };
    }

    /**
     * @param  array<string, mixed>|null  $check
     * @return array<string, mixed>
     */
    protected function diskCard(?array $check, string $summary): array
    {
        $percent = $this->floatMeta($check, 'used_space_percentage') ?? $this->floatFromSummary($summary);
        $warn = (float) config('statamic-ohdear-health-check.disk_space_warning_threshold', 70);
        $fail = (float) config('statamic-ohdear-health-check.disk_space_error_threshold', 90);

        return [
            'value' => $percent !== null ? $this->formatNumber($percent).'%' : '—',
            'valueSuffix' => null,
            'detail' => $percent !== null ? $this->formatNumber($percent).'% disk used' : ($summary !== '' ? $summary : 'not available'),
            'items' => [],
            'bar' => $percent,
            'threshold' => 'Warns above '.$this->formatNumber($warn, 0).'% · fails above '.$this->formatNumber($fail, 0).'%',
            'linkLabel' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $check
     * @return array<string, mixed>
     */
    protected function errorLogCard(?array $check, string $summary): array
    {
        $size = $this->floatMeta($check, 'log_size_in_mb');
        $warn = (int) config('statamic-ohdear-health-check.error_log_warning_threshold', 50);

        return [
            'value' => $size !== null ? $this->formatNumber($size) : '—',
            'valueSuffix' => $size !== null ? ' MB' : null,
            'detail' => $size !== null ? 'log file size' : ($summary !== '' ? $summary : 'not available'),
            'items' => [],
            'bar' => null,
            'threshold' => 'Warns above '.$warn.' MB',
            'linkLabel' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $check
     * @return array<string, mixed>
     */
    protected function storageCard(?array $check, string $summary): array
    {
        $size = Arr::get($check['meta'] ?? [], 'var_folder_size')
            ?: $this->stripPrefix($summary, 'Folder Size: ');
        $warn = (int) config('statamic-ohdear-health-check.storage_folder_size_warning_threshold', 50);

        return [
            'value' => is_string($size) && $size !== '' ? preg_replace('/\s*(MB|GB|KB|B)$/i', '', $size) : '—',
            'valueSuffix' => is_string($size) && preg_match('/\s*(MB|GB|KB|B)$/i', $size, $m) ? ' '.$m[1] : null,
            'detail' => is_string($size) && $size !== '' ? $size.' total' : ($summary !== '' ? $summary : 'not available'),
            'items' => [],
            'bar' => null,
            'threshold' => 'Warns above '.$warn.' MB',
            'linkLabel' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $check
     * @return array<string, mixed>
     */
    protected function forgottenCard(?array $check, string $summary): array
    {
        $files = Arr::get($check['meta'] ?? [], 'unallowed_files_list', []);
        $files = is_array($files) ? array_values($files) : [];
        $count = count($files) ?: ($this->intFromSummary($summary) ?? 0);

        return [
            'value' => (string) $count,
            'valueSuffix' => null,
            'detail' => $count > 0
                ? ($count === 1 ? '1 unexpected file' : "{$count} unexpected files")
                : 'nothing to reclaim',
            'items' => array_slice($files, 0, 2),
            'bar' => null,
            'threshold' => null,
            'linkLabel' => $count > 2 ? "All {$count} files" : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $check
     * @return array{0: string, 1: string}
     */
    protected function monitorDisplay(?string $key, array $check, string $summary, string $status): array
    {
        return match ($key) {
            'disk' => [
                ($p = $this->floatMeta($check, 'used_space_percentage') ?? $this->floatFromSummary($summary)) !== null
                    ? $this->formatNumber($p).'%'
                    : ($status === 'ok' ? 'OK' : Str::ucfirst($status)),
                $summary !== '' ? $summary : 'disk used',
            ],
            'error_log' => [
                ($s = $this->floatMeta($check, 'log_size_in_mb')) !== null ? $this->formatMb($s) : ($status === 'ok' ? 'OK' : Str::ucfirst($status)),
                $summary !== '' ? $summary : 'error log',
            ],
            'storage' => [
                Arr::get($check['meta'] ?? [], 'var_folder_size') ?: ($summary !== '' ? $this->stripPrefix($summary, 'Folder Size: ') : '—'),
                'storage folder',
            ],
            'forgotten' => [
                (string) ($this->intMeta($check, 'unallowed_files_list') ?? $this->intFromSummary($summary) ?? 0),
                $summary !== '' ? $summary : 'public files',
            ],
            'database' => [
                $status === 'ok' ? 'Connected' : 'Down',
                $summary !== '' ? $summary : 'database connectivity',
            ],
            'statamic_version' => [
                (string) Arr::get($check['meta'] ?? [], 'installed_version', $status === 'ok' ? 'OK' : Str::ucfirst($status)),
                ($latest = Arr::get($check['meta'] ?? [], 'latest_version')) ? 'latest '.$latest : ($summary !== '' ? $summary : 'Statamic release'),
            ],
            default => [
                $status === 'ok' ? 'OK' : Str::ucfirst($status),
                $summary !== '' ? $summary : 'application health check',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $check
     */
    protected function summary(array $check): string
    {
        $summary = trim((string) ($check['short_summary'] ?? ''));
        $message = trim((string) ($check['notification_message'] ?? ''));
        $status = $this->normalizeStatus((string) ($check['status'] ?? ''));

        if ($summary === '' || in_array(strtolower($summary), ['ok', 'failed', 'warning', $status], true)) {
            return $message;
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $check
     */
    protected function matchKey(array $check): ?string
    {
        foreach (array_merge($this->applicationHealthChecks, $this->knownTabs) as $key => $definition) {
            if ($this->matches($check, $definition['needles'])) {
                return $key;
            }
        }

        return null;
    }

    protected function tabFor(?string $key): string
    {
        if ($key && isset($this->applicationHealthChecks[$key])) {
            return $this->applicationHealthChecks[$key]['tab'];
        }

        if ($key && isset($this->knownTabs[$key])) {
            return $this->knownTabs[$key]['tab'];
        }

        return 'general';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $checks
     * @param  array<int, string>  $needles
     * @return array<string, mixed>|null
     */
    protected function findCheck(Collection $checks, array $needles): ?array
    {
        return $checks->first(fn (array $check) => $this->matches($check, $needles));
    }

    /**
     * @param  array<string, mixed>  $check
     * @param  array<int, string>  $needles
     */
    protected function matches(array $check, array $needles): bool
    {
        foreach ([(string) ($check['name'] ?? ''), (string) ($check['label'] ?? '')] as $candidate) {
            foreach ($needles as $needle) {
                if (strcasecmp($candidate, $needle) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $check
     */
    protected function floatMeta(?array $check, string $key): ?float
    {
        $value = Arr::get($check['meta'] ?? [], $key);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>|null  $check
     */
    protected function intMeta(?array $check, string $key): ?int
    {
        $value = Arr::get($check['meta'] ?? [], $key);

        if (is_array($value)) {
            return count($value);
        }

        return is_numeric($value) ? (int) $value : null;
    }

    protected function floatFromSummary(mixed $summary): ?float
    {
        if (! is_string($summary) || ! preg_match('/([\d.]+)\s*%/', $summary, $matches)) {
            return null;
        }

        return (float) $matches[1];
    }

    protected function intFromSummary(mixed $summary): ?int
    {
        if (! is_string($summary) || ! preg_match('/(\d+)/', $summary, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    protected function stripPrefix(string $value, string $prefix): string
    {
        return str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : $value;
    }

    protected function formatMb(float $mb): string
    {
        return $mb >= 1024
            ? $this->formatNumber($mb / 1024).' GB'
            : $this->formatNumber($mb).' MB';
    }

    protected function formatNumber(float $number, int $decimals = 2): string
    {
        return rtrim(rtrim(number_format($number, $decimals), '0'), '.');
    }
}
