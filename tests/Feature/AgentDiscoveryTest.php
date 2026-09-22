<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * The machine-readable index of what an agent can do here.
 *
 * The rule throughout: advertise only what the site actually serves. An
 * agent that follows a catalogue to a 404 is worse off than one that
 * found no catalogue, and a manifest claiming an MCP server or a payment
 * channel that does not exist is the same lie as an accessibility badge
 * nobody earned.
 */
class AgentDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument([
            'pages' => ['home' => ['title' => 'Home', 'seo' => ['meta_description' => 'A family dental practice in Hove.']]],
        ]);

        config(['gadya-cms.seo.site_name' => 'Acme Dental', 'gadya-cms.seo.organization.email' => 'hello@acmedental.test']);
    }

    public function test_the_capability_manifest_says_what_the_site_offers_and_the_questions_it_answers(): void
    {
        $response = $this->get('/.well-known/ai-catalog.json')->assertOk();

        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'), 'An agent reading this comes from somewhere else.');

        $response
            ->assertJsonPath('host.name', 'Acme Dental')
            ->assertJsonPath('host.description', 'A family dental practice in Hove.')
            ->assertJsonPath('host.contact', 'hello@acmedental.test');

        $entries = collect($response->json('entries'));

        $this->assertStringStartsWith('urn:air:', (string) $entries->first()['id']);
        $this->assertNotEmpty($entries->first()['representativeQueries'], 'A registry needs questions to index against.');
        $this->assertTrue($entries->contains(fn (array $entry): bool => str_contains((string) $entry['url'], '/llms.txt')));
    }

    public function test_the_api_catalogue_is_a_linkset_anchored_on_the_site(): void
    {
        $response = $this->get('/.well-known/api-catalog')->assertOk();

        $this->assertStringContainsString('application/linkset+json', (string) $response->headers->get('Content-Type'));

        $response
            ->assertJsonPath('linkset.0.anchor', url('/'))
            ->assertJsonPath('linkset.0.service-doc.0.type', 'text/markdown');
    }

    public function test_the_skills_index_publishes_only_reading_skills_each_with_a_digest(): void
    {
        $skills = collect($this->get('/.well-known/agent-skills/index.json')->assertOk()->json('skills'));

        $this->assertTrue($skills->every(fn (array $skill): bool => filled($skill['sha256'])));
        $this->assertTrue($skills->contains('name', 'read-pages-as-markdown'));
        $this->assertFalse(
            $skills->contains(fn (array $skill): bool => str_contains($skill['name'], 'send') || str_contains($skill['name'], 'book')),
            'Nothing that writes: an enquiry sent by an agent is one the client still has to answer.',
        );
    }

    public function test_an_agent_card_the_site_already_publishes_is_named_in_the_catalogue(): void
    {
        Route::middleware('web')->get('/.well-known/agent-card.json', fn () => response()->json(['name' => 'Acme Dental']));

        $this->assertTrue(
            collect($this->get('/.well-known/ai-catalog.json')->assertOk()->json('entries'))
                ->contains(fn (array $entry): bool => str_ends_with((string) $entry['id'], ':agent:card')),
            'A site that already has an agent card should have it named, not duplicated.',
        );
    }

    public function test_a_site_with_no_mcp_server_says_so_with_a_404_rather_than_an_empty_card(): void
    {
        $this->get('/.well-known/mcp/server-card.json')->assertNotFound();
    }

    public function test_a_site_that_runs_an_mcp_server_publishes_a_card_and_names_it_in_the_catalogues(): void
    {
        config(['gadya-cms.seo.mcp.endpoint' => '/mcp', 'gadya-cms.seo.mcp.name' => 'Acme Dental MCP']);

        $this->get('/.well-known/mcp/server-card.json')
            ->assertOk()
            ->assertJsonPath('serverInfo.name', 'Acme Dental MCP')
            ->assertJsonPath('transport.endpoint', url('/mcp'));

        $this->assertTrue(
            collect($this->get('/.well-known/ai-catalog.json')->json('entries'))->contains(fn (array $entry): bool => str_ends_with((string) $entry['id'], ':mcp:server')),
        );
    }
}
