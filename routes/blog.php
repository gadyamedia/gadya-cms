<?php

use Gadya\Cms\Http\Controllers\BlogController;
use Gadya\Cms\Http\Controllers\CommentController;
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

        /*
         * Category and tag archives, before the article route so a term
         * prefix is never mistaken for an article's address.
         */
        Route::get('/'.trim((string) config('gadya-cms.blog.category_prefix', 'category'), '/').'/{slug}', [BlogController::class, 'category'])
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('category');

        Route::get('/'.trim((string) config('gadya-cms.blog.tag_prefix', 'tag'), '/').'/{slug}', [BlogController::class, 'tag'])
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('tag');

        /*
         * Replies from readers. Throttled per address and checked for a
         * honeypot, because a comment box is the most spammed thing on a
         * website.
         */
        Route::post('/{slug}/comments', [CommentController::class, 'store'])
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->middleware('throttle:gadya-cms-forms')
            ->name('comments.store');

        Route::get('/{slug}', [BlogController::class, 'show'])
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('show');
    });
