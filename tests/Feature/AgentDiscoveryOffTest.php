<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * A site that would rather publish none of it. The switch is read when
 * the routes register, so it has to be off before the application exists
 * - which is how a real site would set it, in config.
 */
class AgentDiscoveryOffTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('gadya-cms.seo.discovery', false);
        $app['config']->set('gadya-cms.seo.llms', false);
    }

    public function test_nothing_is_served_under_well_known_when_discovery_is_off(): void
    {
        $this->publishDocument();

        $this->get('/.well-known/ai-catalog.json')->assertNotFound();
        $this->get('/.well-known/api-catalog')->assertNotFound();
        $this->get('/.well-known/agent-skills/index.json')->assertNotFound();
    }

    public function test_the_home_page_does_not_advertise_a_catalogue_it_does_not_serve(): void
    {
        $this->publishDocument();

        Route::middleware('web')->get('/', fn () => response('<html>home</html>'));

        $header = (string) $this->get('/')->assertOk()->headers->get('Link');

        $this->assertStringContainsString('rel="sitemap"', $header, 'The sitemap is still served, so it is still advertised.');
        $this->assertStringNotContainsString('api-catalog', $header, 'What is not served is not advertised.');
    }
}
