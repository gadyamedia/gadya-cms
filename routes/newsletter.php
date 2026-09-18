<?php

use Gadya\Cms\Http\Controllers\NewsletterController;
use Illuminate\Support\Facades\Route;

/*
 * The mailing list sign-up, and the way out of it. Unsubscribing is a
 * signed link rather than a login, because the person clicking it is
 * reading an email, not visiting the site.
 */
Route::post((string) config('gadya-cms.editor.prefix', 'cms').'/newsletter', [NewsletterController::class, 'store'])
    ->middleware(['web', 'throttle:gadya-cms-forms'])
    ->name('gadya-cms.newsletter.store');

Route::get((string) config('gadya-cms.editor.prefix', 'cms').'/newsletter/{subscriber}/unsubscribe', [NewsletterController::class, 'unsubscribe'])
    ->middleware(['web', 'signed'])
    ->name('gadya-cms.newsletter.unsubscribe');
