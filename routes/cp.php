<?php

use Devskio\StatamicOhdearHealthCheck\Controllers\ConfigController;
use Illuminate\Support\Facades\Route;

Route::get('/ohdear-health-check', [ConfigController::class, 'index'])->name('statamic-ohdear-health-check.config');
Route::post('/ohdear-health-check', [ConfigController::class, 'save'])->name('statamic-ohdear-health-check.config.save');
