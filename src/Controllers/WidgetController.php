<?php

namespace Devskio\StatamicOhdearHealthCheck\Controllers;

use Devskio\StatamicOhdearHealthCheck\ServiceProvider;
use Devskio\StatamicOhdearHealthCheck\Widgets\OhdearHealthCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Statamic\Facades\User;

class WidgetController
{
    public function refresh(): RedirectResponse
    {
        abort_unless(User::current()?->can(ServiceProvider::PERMISSION), 403);

        Cache::forget(OhdearHealthCheck::CACHE_KEY);

        return redirect()->back();
    }
}
