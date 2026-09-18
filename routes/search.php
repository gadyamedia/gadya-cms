<?php

use Gadya\Cms\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
 * The site's own search box. A visitor's search is not a page worth
 * indexing, so the results carry noindex and nothing else changes.
 */
Route::get('/'.trim((string) config('gadya-cms.site_search.path', 'search'), '/'), SearchController::class)
    ->middleware('web')
    ->name('gadya-cms.search');
