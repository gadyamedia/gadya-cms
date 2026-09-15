<?php

use Gadya\Cms\Http\Controllers\BlogController;
use Illuminate\Support\Facades\Route;

/*
 * The public blog. Only loaded when `gadya-cms.blog.routes` is on, so an
 * application with its own article templates can keep them.
 */
Route::middleware('web')
    ->prefix(trim((string) config('gadya-cms.blog.prefix', 'blog'), '/'))
    ->name('gadya-cms.blog.')
    ->group(function (): void {
        Route::get('/', [BlogController::class, 'index'])->name('index');
        Route::get('/{slug}', [BlogController::class, 'show'])
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('show');
    });
