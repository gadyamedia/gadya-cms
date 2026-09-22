<?php

use Gadya\Cms\Http\Controllers\AccessibilityStatementController;
use Illuminate\Support\Facades\Route;

/*
 * The accessibility statement, generated from the site's own record of
 * checks and fixes. Only loaded when `gadya-cms.accessibility.statement`
 * is on, so a site with its own hand-written statement keeps it.
 */
Route::middleware('web')
    ->get('/'.trim((string) config('gadya-cms.accessibility.path', 'accessibility-statement'), '/'), AccessibilityStatementController::class)
    ->name('gadya-cms.accessibility.statement');
