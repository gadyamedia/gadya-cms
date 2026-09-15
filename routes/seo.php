<?php

use Gadya\Cms\Http\Controllers\SeoController;
use Illuminate\Support\Facades\Route;

/*
 * The sitemap and robots file, generated from what is actually published.
 * A static file in public/ wins over these, so an application that keeps
 * its own is left alone.
 */
if (config('gadya-cms.seo.sitemap', true)) {
    Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('gadya-cms.sitemap');
}

if (config('gadya-cms.seo.robots', true)) {
    Route::get('robots.txt', [SeoController::class, 'robots'])->name('gadya-cms.robots');
}
