<?php

namespace Devskio\StatamicOhdearHealthCheck\Checks;

use Devskio\LaravelOhdearHealthCheck\Health\Checks\UsedDiskSpaceCheck;

class DiskUsedSpace extends UsedDiskSpaceCheck
{
	public function __construct()
	{
		parent::__construct();

		$this->warnWhenUsedSpaceIsAbovePercentage((float) config('statamic-ohdear-health-check.disk_space_warning_threshold', 70));
		$this->failWhenUsedSpaceIsAbovePercentage((float) config('statamic-ohdear-health-check.disk_space_error_threshold', 90));
	}
}
