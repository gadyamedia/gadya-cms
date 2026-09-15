<?php

use Gadya\Cms\Http\Controllers\AnalyticsEventController;
use Gadya\Cms\Http\Controllers\EditModeController;
use Gadya\Cms\Http\Controllers\InlineEditController;
use Gadya\Cms\Http\Controllers\PreviewController;
use Gadya\Cms\Http\Controllers\PublishController;
use Gadya\Cms\Http\Controllers\StructureController;
use Illuminate\Support\Facades\Route;

/*
 * The live editor. These routes sit on the public site rather than inside
 * the admin panel, because that is the whole point: the client edits the
 * real page, in place, and only the writes come back here.
 */
Route::prefix((string) config('gadya-cms.editor.prefix', 'cms'))
    ->name('gadya-cms.')
    ->middleware(['web', 'auth', 'can:'.(string) config('gadya-cms.gate', 'manage-content')])
    ->group(function (): void {
        Route::post('edit-mode', [EditModeController::class, 'enable'])->name('edit-mode.enable');
        Route::delete('edit-mode', [EditModeController::class, 'disable'])->name('edit-mode.disable');

        Route::post('publish', PublishController::class)->name('publish');

        Route::post('inline', [InlineEditController::class, 'update'])
            ->middleware('throttle:gadya-cms-inline')
            ->name('inline.update');

        Route::post('structure', [StructureController::class, 'update'])
            ->middleware('throttle:gadya-cms-inline')
            ->name('structure.update');
    });

/*
 * The analytics beacon. Open to visitors by necessity - it is the public
 * site that reports what they did - so it is throttled and accepts only a
 * fixed list of event names.
 */
Route::post((string) config('gadya-cms.editor.prefix', 'cms').'/events', AnalyticsEventController::class)
    ->middleware(['web', 'throttle:gadya-cms-events'])
    ->name('gadya-cms.events.store');

/*
 * Preview links. Signed and expiring, and open to anyone holding one: the
 * point is to show a draft to someone without an account.
 */
Route::get((string) config('gadya-cms.editor.prefix', 'cms').'/preview', [PreviewController::class, 'show'])
    ->middleware(['web', 'signed'])
    ->name('gadya-cms.preview');

Route::delete((string) config('gadya-cms.editor.prefix', 'cms').'/preview', [PreviewController::class, 'stop'])
    ->middleware('web')
    ->name('gadya-cms.preview.stop');
