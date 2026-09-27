<?php

use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Http\Controllers\TranslationController;
use Illuminate\Support\Facades\Route;

/*
 * The live editor's language buttons. Posted from a page in another
 * language (/es/cms/...), so the request itself says which language.
 */
Route::prefix((string) config('gadya-cms.editor.prefix', 'cms'))
    ->name('gadya-cms.')
    ->middleware(['web', 'auth', 'can:'.(string) config('gadya-cms.gate', 'manage-content'), 'can:'.Abilities::gate(Abilities::CONTENT)])
    ->group(function (): void {
        Route::post('translations/translate', [TranslationController::class, 'translate'])->name('translations.translate');
        Route::post('translations/review', [TranslationController::class, 'review'])->name('translations.review');
    });
