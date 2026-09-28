<?php

use Gadya\Cms\Http\Controllers\BuilderFormController;
use Illuminate\Support\Facades\Route;

/*
 * Forms built in the panel. Sending one goes through the same address as
 * every other form (gadya-cms.forms.store, in editor.php); these are the
 * rest: the form's own page, the iframe for other sites, finishing later,
 * the form's own figures, and the admin's signed file links.
 */
$cms = (string) config('gadya-cms.editor.prefix', 'cms');
$page = trim((string) config('gadya-cms.forms.builder.path', 'forms'), '/');

Route::get($page.'/{slug}', [BuilderFormController::class, 'page'])
    ->where('slug', '[a-z0-9-]+')
    ->middleware('web')
    ->name('gadya-cms.forms.page');

/*
 * No session: inside another site's page the browser will not send this
 * site's cookies, so there is no CSRF token to check. The seal, the
 * honeypot and the rate limit stand in for it.
 */
Route::get($page.'/{slug}/embed', [BuilderFormController::class, 'embed'])
    ->where('slug', '[a-z0-9-]+')
    ->name('gadya-cms.forms.embed');

Route::post($page.'/{slug}/embed', [BuilderFormController::class, 'embedStore'])
    ->where('slug', '[a-z0-9-]+')
    ->middleware('throttle:gadya-cms-forms')
    ->name('gadya-cms.forms.embed.store');

Route::post($cms.'/forms/{slug}/save', [BuilderFormController::class, 'save'])
    ->where('slug', '[a-z0-9-]+')
    ->middleware(['web', 'throttle:gadya-cms-forms'])
    ->name('gadya-cms.forms.save');

Route::get($cms.'/forms/{slug}/resume/{token}', [BuilderFormController::class, 'resume'])
    ->where(['slug' => '[a-z0-9-]+', 'token' => '[A-Za-z0-9]+'])
    ->middleware(['web', 'signed'])
    ->name('gadya-cms.forms.resume');

Route::post($cms.'/forms/{slug}/events', [BuilderFormController::class, 'events'])
    ->where('slug', '[a-z0-9-]+')
    ->middleware('throttle:gadya-cms-events')
    ->name('gadya-cms.forms.events');

Route::get($cms.'/form-files/{submission}/{field}/{index}', [BuilderFormController::class, 'file'])
    ->where(['field' => '[a-z0-9_]+', 'index' => '[0-9]+'])
    ->middleware(['web', 'signed'])
    ->name('gadya-cms.forms.file');
