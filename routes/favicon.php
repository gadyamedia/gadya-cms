<?php

use Gadya\Cms\Http\Controllers\FaviconController;
use Illuminate\Support\Facades\Route;

/*
 * The browser-tab icon, drawn from the site's own logo. A real file in
 * public/ is served by the web server before Laravel is asked, so a site
 * with its own favicon never reaches these.
 */
Route::middleware('web')->group(function (): void {
    Route::get('favicon.ico', [FaviconController::class, 'ico'])->name('gadya-cms.favicon.ico');
    Route::get('favicon-{size}.png', [FaviconController::class, 'png'])
        ->whereNumber('size')
        ->name('gadya-cms.favicon.png');
    Route::get('apple-touch-icon.png', [FaviconController::class, 'apple'])->name('gadya-cms.favicon.apple');
    Route::get('site.webmanifest', [FaviconController::class, 'manifest'])->name('gadya-cms.favicon.manifest');
});
