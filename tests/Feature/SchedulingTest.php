<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\PublicDocument;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Resources\Pages\PageResource;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Tests\TestCase;

class SchedulingTest extends TestCase
{
    public function test_a_page_is_hidden_before_its_publish_date_and_after_its_unpublish_date(): void
    {
        $registry = app(PageRegistry::class);

        $this->assertTrue($registry->isHidden(['publish_at' => now()->addDay()->toIso8601String()]));
        $this->assertTrue($registry->isHidden(['unpublish_at' => now()->subMinute()->toIso8601String()]));
        $this->assertFalse($registry->isHidden(['publish_at' => now()->subDay()->toIso8601String(), 'unpublish_at' => now()->addDay()->toIso8601String()]));
        $this->assertFalse($registry->isHidden(['publish_at' => '', 'unpublish_at' => null]));
        $this->assertTrue($registry->isScheduled(['publish_at' => now()->addHour()->toIso8601String()]));
        $this->assertFalse($registry->isScheduled(['status' => 'archived', 'publish_at' => now()->addHour()->toIso8601String()]));
    }

    public function test_a_scheduled_page_leaves_the_menu_until_its_time_comes(): void
    {
        $this->publishDocument();
        $repository = app(SiteContentRepository::class);

        $document = $repository->draft();
        $document['pages']['about']['publish_at'] = now()->addDay()->toIso8601String();
        $this->publishDocument($document);

        $labels = fn (): array => array_column(app(PublicDocument::class)->from($repository->published())['nav'], 'label');

        $this->assertNotContains('About', $labels());

        $this->travel(2)->days();

        $this->assertContains('About', $labels(), 'No cache needs clearing: the day arrives and the page appears.');
    }

    public function test_the_panel_labels_a_scheduled_page(): void
    {
        $this->publishDocument();

        $page = Page::query()->where('slug', 'about')->firstOrFail();
        $this->assertSame('Visible', PageResource::visibilityLabel($page));

        $page->forceFill(['draft' => [...$page->draft, 'publish_at' => now()->addDay()->toIso8601String()]])->save();
        $this->assertSame('Scheduled', PageResource::visibilityLabel($page->fresh()));

        $page->forceFill(['draft' => [...$page->draft, 'publish_at' => null, 'unpublish_at' => now()->subDay()->toIso8601String()]])->save();
        $this->assertSame('Expired', PageResource::visibilityLabel($page->fresh()));
    }
}
