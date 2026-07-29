<?php

namespace Devskio\StatamicOhdearHealthCheck\Controllers;

use Devskio\LaravelOhdearHealthCheck\Core\CheckRunner;
use Devskio\LaravelOhdearHealthCheck\Http\Controllers\HealthCheckController as BaseHealthCheckController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class HealthCheckController extends BaseHealthCheckController
{
    public function check(CheckRunner $runner): JsonResponse|Response
    {
        return $this->__invoke($runner);
    }
}
