<?php

namespace Devskio\StatamicOhdearHealthCheck\Checks;

use Devskio\LaravelOhdearHealthCheck\Health\Checks\ErrorLogCheck;

class PhpErrorLogSize extends ErrorLogCheck
{
	public function __construct()
	{
		parent::__construct();

		$this->warnWhenLogSizeIsLargerInMb((int) config('statamic-ohdear-health-check.error_log_warning_threshold', 50));
		$this->failWhenLogSizeIsLargerInMb((int) config('statamic-ohdear-health-check.error_log_error_threshold', 500));
	}
}
