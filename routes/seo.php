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

if (config('gadya-cms.seo.llms', true)) {
    Route::get('llms.txt', [SeoController::class, 'llms'])->name('gadya-cms.llms');
}

if (config('gadya-cms.seo.robots', true)) {
    Route::get('robots.txt', [SeoController::class, 'robots'])->name('gadya-cms.robots');
}

/*
 * The machine-readable index of what an agent can do here: an ARD
 * capability manifest, an RFC 9727 API catalogue, a skills index, and -
 * only where the application declares one - an MCP server card. Each
 * lists what the site actually serves and nothing else.
 */
if (config('gadya-cms.seo.discovery', true)) {
    Route::get('.well-known/ai-catalog.json', [SeoController::class, 'aiCatalog'])->name('gadya-cms.ai-catalog');
    Route::get('.well-known/api-catalog', [SeoController::class, 'apiCatalog'])->name('gadya-cms.api-catalog');
    Route::get('.well-known/agent-skills/index.json', [SeoController::class, 'agentSkills'])->name('gadya-cms.agent-skills');
    Route::get('.well-known/mcp/server-card.json', [SeoController::class, 'mcpServerCard'])->name('gadya-cms.mcp-card');
}
