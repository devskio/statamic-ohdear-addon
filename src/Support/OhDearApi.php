<?php

namespace Devskio\StatamicOhdearHealthCheck\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin read-only client for the Oh Dear API (https://ohdear.app/api).
 *
 * Only the handful of endpoints the dashboard widget needs are implemented, and
 * every one of them degrades to null rather than throwing: a monitoring widget
 * that takes the control panel down with it would be its own punchline.
 */
class OhDearApi
{
    /**
     * Dashboard paths for a check's own report, relative to /monitors/{id}/check/.
     *
     * Only the ones confirmed against Oh Dear are listed; any other check type falls
     * back to the monitor's active-checks page rather than risking a 404.
     *
     * @var array<string, string>
     */
    protected const CHECK_PATHS = [
        'uptime' => 'uptime/report',
        'broken_links' => 'broken-links/report',
        'mixed_content' => 'mixed-content/report',
        'certificate_health' => 'certificate-health/report',
        'domain' => 'domain/report',
        'dns_blocklist' => 'dns-blocklist/report',
        'application_health' => 'application-health/run',
        'dns' => 'dns/latest',
        'lighthouse' => 'lighthouse/latest',
    ];

    protected ?string $error = null;

    public function __construct(
        protected ?string $token,
        protected ?int $monitorId,
        protected string $baseUrl = 'https://ohdear.app/api',
        protected int $timeout = 5,
    ) {
    }

    public static function fromConfig(): self
    {
        $monitorId = config('statamic-ohdear-health-check.monitor_id');

        return new self(
            token: self::nullableString(config('statamic-ohdear-health-check.api_token')),
            monitorId: is_numeric($monitorId) ? (int) $monitorId : null,
            baseUrl: rtrim((string) config('statamic-ohdear-health-check.api_base_url', 'https://ohdear.app/api'), '/'),
            timeout: (int) config('statamic-ohdear-health-check.api_timeout', 5),
        );
    }

    public function configured(): bool
    {
        return $this->token !== null && $this->monitorId !== null;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function monitorId(): ?int
    {
        return $this->monitorId;
    }

    /**
     * The monitor's page in the Oh Dear dashboard.
     */
    public function dashboardUrl(): ?string
    {
        return $this->monitorUrl() === null ? null : $this->monitorUrl().'/active-checks';
    }

    /**
     * The dashboard report for a single check, e.g. .../check/uptime/report.
     */
    public function checkUrl(string $type): ?string
    {
        if (! $monitor = $this->monitorUrl()) {
            return null;
        }

        return isset(self::CHECK_PATHS[$type])
            ? $monitor.'/check/'.self::CHECK_PATHS[$type]
            : $monitor.'/active-checks';
    }

    protected function monitorUrl(): ?string
    {
        return $this->monitorId === null
            ? null
            : preg_replace('#/api/?$#', '', $this->baseUrl).'/monitors/'.$this->monitorId;
    }

    /**
     * The monitor with its checks: label, summarized_check_result, latest_run_date, checks[].
     *
     * @return array<string, mixed>|null
     */
    public function monitor(): ?array
    {
        return $this->get('/monitors/'.$this->monitorId);
    }

    /**
     * Average uptime percentage over the given window.
     */
    public function uptimePercentage(int $days = 30): ?float
    {
        $points = $this->get('/monitors/'.$this->monitorId.'/uptime', [
            'filter[started_at]' => Carbon::now()->subDays($days)->format('YmdHis'),
            'filter[ended_at]' => Carbon::now()->format('YmdHis'),
            'split' => 'day',
        ]);

        $percentages = collect($points ?? [])
            ->pluck('uptime_percentage')
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (float) $value);

        return $percentages->isEmpty() ? null : round($percentages->avg(), 2);
    }

    /**
     * Certificate details: issuer, valid_from, valid_until.
     *
     * @return array<string, mixed>|null
     */
    public function certificate(): ?array
    {
        $health = $this->get('/certificate-health/'.$this->monitorId);

        return Arr::get($health ?? [], 'certificate_details');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>|null
     */
    protected function get(string $path, array $query = []): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->timeout($this->timeout)
                ->get($this->baseUrl.$path, $query);
        } catch (Throwable $e) {
            $this->error = 'Oh Dear API unreachable';

            return null;
        }

        if ($response->status() === 401 || $response->status() === 403) {
            $this->error = 'Oh Dear API token was rejected';

            return null;
        }

        if ($response->status() === 404) {
            $this->error = 'Oh Dear monitor '.$this->monitorId.' was not found';

            return null;
        }

        if ($response->failed()) {
            $this->error = 'Oh Dear API returned '.$response->status();

            return null;
        }

        $data = $response->json();

        return is_array($data) ? $data : null;
    }

    protected static function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
