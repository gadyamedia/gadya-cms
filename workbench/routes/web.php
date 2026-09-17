<?php

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\SiteController;

Route::get('/', [SiteController::class, 'show'])->name('home');

Route::get('/{slug}', [SiteController::class, 'show'])
    ->where('slug', '(?!admin$|cms$|blog$|livewire$|storage$)[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('pages.show');
