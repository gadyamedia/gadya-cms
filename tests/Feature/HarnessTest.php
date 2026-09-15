<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Tests\TestCase;

class HarnessTest extends TestCase
{
    public function test_the_fixture_document_is_seeded_and_published(): void
    {
        $this->publishDocument();

        $document = app(SiteContentRepository::class)->published();

        $this->assertSame('Welcome', $document['pages']['home']['title']);
        $this->assertDatabaseCount('gadyacms_pages', 4);
    }

    public function test_an_editor_can_open_the_panel_and_a_visitor_cannot(): void
    {
        $this->publishDocument();

        $this->actingAs($this->editor())->get('/admin')->assertOk();
        $this->actingAs($this->visitor())->get('/admin')->assertForbidden();
    }
}
