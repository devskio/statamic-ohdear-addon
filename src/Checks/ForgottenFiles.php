<?php

namespace Devskio\StatamicOhdearHealthCheck\Checks;

use OhDear\HealthCheckResults\CheckResult;
use Statamic\Facades\Config;

/**
 * Class ForgottenFiles
 * @package Devskio\StatamicOhDearHealthCheck\Checks
 */
class ForgottenFiles extends AbstractCheck
{
    /**
     * List of allowed files and folders.
     *
     * @var array
     */
    protected $allowedFiles = [
        '.htaccess',
        'index.php',
        'robots.txt',
        'assets',
        'build',
        'favicons',
        'fonts',
        'icons',
        'img',
        'static',
        'vendor',
        'visuals',
        'hot',
    ];

    /**
     * Run the health check.
     *
     * @return CheckResult The result of the health check.
     */
    public function run(): CheckResult
    {
        $count = 0;
        $forgottenFilesList = [];
        $items = $this->getItemsInRootDirectory();
        $status = CheckResult::STATUS_SKIPPED;

        if ($this->configuration['allowedFilesWarningCustomCheckEnabled']) {
            $this->allowedFiles = array_merge(
                $this->allowedFiles,
                $this->configuration['allowedFiles'],
            );
            foreach ($items as $item) {
                if (!$this->isAllowedItem($item)) {
                    $forgottenFilesList[] = $item;
                    $count++;
                }
            }

            $status = ($count > 0) ? CheckResult::STATUS_FAILED : CheckResult::STATUS_OK;
        }

        $identifier = self::getIdentifier();
        return new CheckResult(
            $identifier,
            'Forgotten Files',
            'Found ' . $count . ' unallowed files or folders',
            $count . ' unallowed files',
            $status,
            ['unallowed_files_list' => $forgottenFilesList]
        );
    }

    /**
     * Get the list of items in the root directory.
     *
     * @return array The list of items.
     */
    private function getItemsInRootDirectory(): array
    {
        $publicPath = public_path();

        if (! is_dir($publicPath)) {
            return [];
        }

        $items = scandir($publicPath);

        return $items === false ? [] : $items;
    }

    /**
     * Check if an item is allowed.
     *
     * @param string $item The item to check.
     * @return bool True if the item is allowed, false otherwise.
     */
    private function isAllowedItem(string $item): bool
    {
        if ($item === '.' || $item === '..') {
            return true;
        }

        foreach ($this->allowedFiles as $pattern) {
            if (fnmatch($pattern, $item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Default configuration for this check.
     *
     * @return array
     */
    public function getDefaultConfiguration(): array
    {
        return [
            'allowedFilesWarningCustomCheckEnabled' => Config::get('statamic-ohdear-health-check.enable_forgotten_files_check', true),
            'allowedFiles' => Config::get('statamic-ohdear-health-check.allowed_files', []),
        ];
    }
}
