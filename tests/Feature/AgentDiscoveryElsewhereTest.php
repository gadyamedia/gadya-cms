<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Seo\AgentDiscovery;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * A site that writes its own llms.txt and sitemap, with ours switched
 * off. The addresses still answer, so they still belong in the
 * catalogues; what must never be advertised is an address nothing
 * answers at all.
 */
class AgentDiscoveryElsewhereTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('gadya-cms.seo.llms', false);
        $app['config']->set('gadya-cms.seo.sitemap', false);
    }

    public function test_an_address_the_application_serves_itself_is_still_advertised(): void
    {
        $this->publishDocument();

        Route::middleware('web')->get('/llms.txt', fn () => response('# Our own', 200, ['Content-Type' => 'text/markdown']));

        $entries = collect(app(AgentDiscovery::class)->aiCatalog()['entries']);

        $this->assertTrue(
            $entries->contains(fn (array $entry): bool => str_contains((string) $entry['url'], '/llms.txt')),
            'The application serves it, so an agent should be told where it is.',
        );

        $this->assertFalse(
            $entries->contains(fn (array $entry): bool => str_contains((string) $entry['url'], '/sitemap.xml')),
            'Nothing answers at /sitemap.xml here, so nothing points at it.',
        );
    }
}
