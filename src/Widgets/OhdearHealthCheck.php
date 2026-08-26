<?php

namespace Devskio\StatamicOhdearHealthCheck\Widgets;

use Devskio\LaravelOhdearHealthCheck\Core\CheckRunner;
use Devskio\StatamicOhdearHealthCheck\ServiceProvider;
use Devskio\StatamicOhdearHealthCheck\Support\OhDearApi;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Statamic\Facades\User;
use Statamic\Widgets\Widget;

class OhdearHealthCheck extends Widget
{
    public const CACHE_KEY = 'statamic-ohdear-health-check.widget.v5';

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

    /**
     * Fallback display names for Oh Dear check types, used when the API omits a label.
     *
     * @var array<string, string>
     */
    protected array $ohDearLabels = [
        'uptime' => 'Uptime',
        'performance' => 'Performance',
        'broken_links' => 'Broken links',
        'mixed_content' => 'Mixed content',
        'certificate_health' => 'Certificate health',
        'lighthouse' => 'Lighthouse',
        'cron' => 'Scheduled tasks',
        'application_health' => 'Application health',
        'sitemap' => 'Sitemap',
        'dns' => 'DNS',
        'domain' => 'Domain expiry',
        'ai' => 'AI',
        'ports' => 'Ports',
        'dns_blocklist' => 'DNS blocklist',
    ];

    public function html()
    {
        if (! $this->authorized()) {
            return;
        }

        $ttl = max(0, (int) $this->config('cache', 60));
        $payload = $ttl > 0
            ? Cache::remember(self::CACHE_KEY, $ttl, fn () => $this->gather())
            : $this->gather();

        $checks = collect($payload['local']['checks'] ?? []);
        $ohDear = $payload['ohdear'] ?? ['connected' => false, 'error' => null];
        $connected = (bool) ($ohDear['connected'] ?? false);
        $configUrl = cp_route('statamic-ohdear-health-check.config');

        // Connected to Oh Dear, the strip belongs to its checks: uptime, certificates,
        // DNS and the rest. Otherwise it falls back to whatever runs locally.
        $monitors = $connected
            ? ($ohDear['monitors'] ?? [])
            : $this->monitors($checks, $configUrl);

        $statuses = collect($monitors)->pluck('status');
        $passing = $statuses->filter(fn (string $s) => $s === 'ok')->count();
        $warning = $statuses->filter(fn (string $s) => $s === 'warning')->count();
        $failed = $statuses->filter(fn (string $s) => $s === 'failed')->count();
        $total = $statuses->reject(fn (string $s) => $s === 'skipped')->count();

        $localStatus = $this->normalizeStatus((string) ($payload['local']['status'] ?? 'failed'));
        $status = $connected
            ? $this->worstStatus($statuses->push($localStatus)->all())
            : $localStatus;

        return view('statamic-ohdear-health-check::widgets.health-check', [
            'site' => $connected ? ($ohDear['label'] ?? $this->siteLabel()) : $this->siteLabel(),
            'status' => $status,
            'headline' => $this->headline($status, $monitors),
            'passing' => $passing,
            'total' => max($total, 1),
            'warningCount' => $warning,
            'failedCount' => $failed,
            'finishedAt' => $connected
                ? ($ohDear['finishedAt'] ?? $payload['local']['finished_at'] ?? null)
                : ($payload['local']['finished_at'] ?? null),
            'monitors' => $monitors,
            'applicationHealth' => $this->applicationHealth($checks, $configUrl),
            'configUrl' => $configUrl,
            'reportUrl' => $connected ? ($ohDear['url'] ?? $this->reportUrl()) : $this->reportUrl(),
            'refreshUrl' => cp_route('statamic-ohdear-health-check.widget.refresh'),
            'cacheSeconds' => $ttl,
            'connected' => $connected,
            'ohDearError' => $ohDear['error'] ?? null,
            'applicationHealthUrl' => $connected ? ($ohDear['applicationHealthUrl'] ?? null) : null,
        ]);
    }

    protected function authorized(): bool
    {
        return (bool) User::current()?->can(ServiceProvider::WIDGET_PERMISSION);
    }

    /**
     * @return array{local: array<string, mixed>, ohdear: array<string, mixed>}
     */
    protected function gather(): array
    {
        return [
            'local' => $this->runChecks(),
            'ohdear' => $this->fetchOhDear(),
        ];
    }

    /**
     * Everything Oh Dear itself knows about the monitor: uptime, performance, broken
     * links, certificates, DNS, domain expiry, scheduled tasks and the rest.
     *
     * @return array<string, mixed>
     */
    protected function fetchOhDear(): array
    {
        $api = OhDearApi::fromConfig();

        if (! $api->configured()) {
            return ['connected' => false, 'error' => null];
        }

        if (! $monitor = $api->monitor()) {
            return ['connected' => false, 'error' => $api->error()];
        }

        $checks = collect($monitor['checks'] ?? [])
            ->filter(fn (array $check) => (bool) ($check['enabled'] ?? true));

        $hasType = fn (string $type) => $checks->contains(fn (array $check) => ($check['type'] ?? null) === $type);

        // Only worth the extra round trips when the monitor actually runs those checks.
        $uptime = $hasType('uptime') ? $api->uptimePercentage() : null;
        $certificate = $hasType('certificate_health') ? $api->certificate() : null;

        return [
            'connected' => true,
            'error' => $api->error(),
            'label' => $monitor['label'] ?? null,
            'status' => $this->normalizeStatus((string) ($monitor['summarized_check_result'] ?? '')),
            'finishedAt' => $monitor['latest_run_date'] ?? null,
            'url' => $api->dashboardUrl(),
            'applicationHealthUrl' => $api->checkUrl('application_health'),
            'monitors' => $checks
                ->map(fn (array $check) => $this->ohDearMonitor($check, $uptime, $certificate, $api))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $check
     * @param  array<string, mixed>|null  $certificate
     * @return array<string, mixed>
     */
    protected function ohDearMonitor(array $check, ?float $uptime, ?array $certificate, OhDearApi $api): array
    {
        $type = (string) ($check['type'] ?? '');
        $result = trim((string) ($check['latest_run_result'] ?? ''));
        $status = $result === '' ? 'skipped' : $this->normalizeStatus($result);
        $summary = trim((string) ($check['summary'] ?? ''));
        $checkedAgo = ($endedAt = $check['latest_run_ended_at'] ?? null)
            ? 'checked '.Carbon::parse($endedAt)->diffForHumans()
            : 'no result yet';

        [$value, $note] = match ($type) {
            'uptime' => $uptime !== null
                ? [$this->formatNumber($uptime).'%', 'last 30 days']
                : $this->summaryDisplay($summary, $status, $checkedAgo),
            'certificate_health' => $this->certificateDisplay($certificate, $summary, $status, $checkedAgo),
            default => $this->summaryDisplay($summary, $status, $checkedAgo),
        };

        if ($check['active_snooze'] ?? null) {
            $note = 'snoozed · '.$note;
        }

        return [
            'name' => $check['label'] ?? $this->ohDearLabels[$type] ?? Str::headline($type),
            'value' => $value,
            'note' => $note,
            'status' => $status,
            'bad' => in_array($status, ['failed', 'warning'], true),
            'url' => ($url = $api->checkUrl($type)) ?? cp_route('statamic-ohdear-health-check.config'),
            'external' => $url !== null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $certificate
     * @return array{0: string, 1: string}
     */
    protected function certificateDisplay(?array $certificate, string $summary, string $status, string $checkedAgo): array
    {
        $validUntil = Arr::get($certificate ?? [], 'valid_until');
        $issuer = trim((string) Arr::get($certificate ?? [], 'issuer', ''));

        if (! $validUntil) {
            return $this->summaryDisplay($summary, $status, $checkedAgo);
        }

        $days = (int) Carbon::now()->startOfDay()->diffInDays(Carbon::parse($validUntil), false);

        return [
            $days.($days === 1 ? ' day' : ' days'),
            $issuer !== '' ? Str::limit($issuer, 28) : 'until expiry',
        ];
    }

    /**
     * A short summary reads well as the cell's headline value; a longer one would be
     * truncated there, so it moves to the note and the result word takes its place.
     *
     * @return array{0: string, 1: string}
     */
    protected function summaryDisplay(string $summary, string $status, string $checkedAgo): array
    {
        if ($summary === '') {
            return [$this->statusWord($status), $checkedAgo];
        }

        return mb_strlen($summary) <= 18
            ? [$summary, $checkedAgo]
            : [$this->statusWord($status), $summary];
    }

    protected function statusWord(string $status): string
    {
        return match ($status) {
            'ok' => 'OK',
            'warning' => 'Warning',
            'failed' => 'Failed',
            default => '—',
        };
    }

    /**
     * @param  array<int, string>  $statuses
     */
    protected function worstStatus(array $statuses): string
    {
        foreach (['failed', 'warning', 'ok'] as $candidate) {
            if (in_array($candidate, $statuses, true)) {
                return $candidate;
            }
        }

        return 'ok';
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
            'ok', 'healthy', 'success', 'succeeded' => 'ok',
            'warning', 'warn' => 'warning',
            'skipped', 'skip' => 'skipped',
            default => 'failed',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $monitors
     */
    protected function headline(string $status, array $monitors): string
    {
        if ($status === 'ok') {
            return 'All checks passing';
        }

        $problem = collect($monitors)->first(
            fn (array $monitor) => ($monitor['status'] ?? null) === $status
        );

        if (! $problem) {
            return $status === 'warning' ? 'Attention needed' : 'Checks failing';
        }

        $name = (string) ($problem['name'] ?? 'Check');
        $note = Str::after(trim((string) ($problem['note'] ?? '')), 'snoozed · ');

        // Timestamps and placeholders say nothing a headline needs; the check's own summary does.
        $substantive = $note !== ''
            && $note !== 'no result yet'
            && ! Str::startsWith($note, ['checked ', 'snoozed']);

        return $substantive
            ? Str::limit($name.' — '.$note, 72)
            : $name.($status === 'warning' ? ' warning' : ' failed');
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
                'status' => $status,
                'bad' => in_array($status, ['failed', 'warning'], true),
                'url' => $configUrl.'#'.$this->tabFor($key),
                'external' => false,
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
