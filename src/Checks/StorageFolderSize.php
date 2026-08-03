<?php

namespace Devskio\StatamicOhdearHealthCheck\Checks;

use OhDear\HealthCheckResults\CheckResult;
use Statamic\Facades\Config;

/**
 * Class StorageFolderSize
 * @package Devskio\StatamicOhDearHealthCheck\Checks
 */
class StorageFolderSize extends AbstractCheck
{
    /**
     * Run the health check.
     *
     * @return CheckResult The result of the health check.
     */
    public function run(): CheckResult
    {
        $identifier = self::getIdentifier();
        $status = CheckResult::STATUS_SKIPPED;
        $storageFolderSize = 0;

        $meta = ['var_folder_size' => $this->formatBytes($storageFolderSize)];

        if ($this->configuration['storageFolderSizeWarningCustomCheckEnabled']) {
            try {
                $storageFolderSize = $this->getStorageFolderSizeExcluding([
                    'framework',
                    'debugbar',
                ]);

                $status = $this->determineStatus(
                    $storageFolderSize,
                    $this->configuration['storageFolderSizeWarningThresholdError'],
                    $this->configuration['storageFolderSizeWarningThresholdWarning']
                );
            } catch (\Throwable $e) {
                $status = CheckResult::STATUS_CRASHED;
                $meta['exception'] = get_class($e);
                $meta['exception_message'] = $e->getMessage();
            }
        }

        $meta['var_folder_size'] = $this->formatBytes($storageFolderSize);

        return new CheckResult(
            $identifier,
            'Storage Folder Size',
            'Folder Size: ' . $this->formatBytes($storageFolderSize),
            'Folder Size: ' . $this->formatBytes($storageFolderSize),
            $status,
            $meta
        );
    }

    /**
     * Calculate the storage folder size excluding specific first-level directories.
     *
     * Example: excluding `framework` means excluding `storage/framework/**`.
     *
     * @param array<int, string> $excludeTopLevelDirs
     */
    protected function getStorageFolderSizeExcluding(array $excludeTopLevelDirs): int
    {
        $storagePath = storage_path();

        $size = 0;
        foreach (new \DirectoryIterator($storagePath) as $fileInfo) {
            if ($fileInfo->isDot()) {
                continue;
            }

            if ($fileInfo->isDir() && in_array($fileInfo->getFilename(), $excludeTopLevelDirs, true)) {
                continue;
            }

            // Avoid recursing into symlinks.
            if ($fileInfo->isLink()) {
                $size += $fileInfo->getSize();
                continue;
            }

            if ($fileInfo->isDir()) {
                $size += $this->getFolderSize($fileInfo->getPathname());
                continue;
            }

            $size += $fileInfo->getSize();
        }

        return $size;
    }

    /**
     * Default configuration for this check.
     *
     * @return array
     */
    public function getDefaultConfiguration(): array
    {
        return [
            'storageFolderSizeWarningCustomCheckEnabled' => Config::get('statamic-ohdear-health-check.enable_storage_folder_size_check', true),
            'storageFolderSizeWarningThresholdError' => Config::get('statamic-ohdear-health-check.storage_folder_size_error_threshold', 500) * $this->toBytesModifier,
            'storageFolderSizeWarningThresholdWarning' => Config::get('statamic-ohdear-health-check.storage_folder_size_warning_threshold', 50) * $this->toBytesModifier,
        ];
    }
}
