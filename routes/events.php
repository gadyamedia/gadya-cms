<?php

use Gadya\Cms\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

/*
 * What is on. The calendar file comes before the event route so a phone
 * subscribing to events.ics is never told there is no such event.
 */
Route::middleware('web')->group(function (): void {
    $prefix = trim((string) config('gadya-cms.events.prefix', 'events'), '/');

    Route::get("/{$prefix}.ics", [EventController::class, 'calendar'])->name('gadya-cms.events.calendar');
    Route::get("/{$prefix}", [EventController::class, 'index'])->name('gadya-cms.events.index');
    Route::get("/{$prefix}/{slug}", [EventController::class, 'show'])
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('gadya-cms.events.show');
});
