<?php

namespace Devskio\StatamicOhdearHealthCheck\Checks;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use OhDear\HealthCheckResults\CheckResult;
use Statamic\Statamic;

/**
 * Class StatamicVersion
 * @package Devskio\StatamicOhdearHealthCheck\Checks
 */
class StatamicVersion extends AbstractCheck
{
    /**
     * Statamic version url
     */
    const VERSION_URL = 'https://api.github.com/repos/statamic/cms/releases/latest';

    /**
     * Run the health check.
     *
     * @return CheckResult The result of the health check.
     */
    public function run(): CheckResult
    {
        $identifier = self::getIdentifier();
        $status = CheckResult::STATUS_SKIPPED;
        $message = 'Statamic version check is disabled';
        $currentVersion = 'N/A';
        $latestVersion = 'N/A';

        if ($this->configuration['statamicVersionCheckEnabled']) {
            try {
                $currentVersion = Statamic::version();
                $latestVersion = ltrim($this->getLatestVersion(), 'v');

                $status = version_compare($latestVersion, $currentVersion) < 1 ? CheckResult::STATUS_OK : CheckResult::STATUS_WARNING;
                $message = $status === CheckResult::STATUS_OK ? 'Installed Statamic version ' . $currentVersion . ' is up to date' : 'Update available: Installed Statamic version is ' . $currentVersion . ', Latest version is ' . $latestVersion;
            } catch (\Throwable $e) {
                $status = CheckResult::STATUS_CRASHED;
                $message = $e->getMessage();
            }
        }

        return new CheckResult(
            $identifier,
            'Statamic Version',
            $message,
            $status,
            $status,
            ['installed_version' => $currentVersion, 'latest_version' => $latestVersion]
        );
    }

    /**
     * Get the latest version.
     *
     * @return string The latest Statamic version.
     */
    private function getLatestVersion(): string
    {
        return Cache::remember('statamic-ohdear-health-check.latest_version', now()->addHours(6), function (): string {
            $client = new Client([
                'timeout' => 5,
                'headers' => [
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => sprintf('%s Statamic Oh Dear Health Check', (string) config('app.name', 'Laravel')),
                ],
            ]);

            $response = $client->get(self::VERSION_URL);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('Unable to fetch the latest version of Statamic.');
            }

            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            return $data['tag_name'] ?? throw new \RuntimeException('Invalid response from GitHub.');
        });
    }

    /**
     * Default configuration for this check.
     *
     * @return array
     */
    public function getDefaultConfiguration(): array
    {
        return [
            'statamicVersionCheckEnabled' => config('statamic-ohdear-health-check.enable_statamic_version_check', true),
        ];
    }

}
