<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Blog\BlogRepository;
use Gadya\Cms\Filament\Resources\Posts\Pages\EditPost;
use Gadya\Cms\Filament\Resources\Terms\Pages\ListTerms;
use Gadya\Cms\Filament\Resources\Terms\TermResource;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Models\Term;
use Gadya\Cms\Tests\TestCase;
use Livewire\Livewire;

class TaxonomyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    private function term(string $name, string $taxonomy = Term::CATEGORY): Term
    {
        return Term::query()->create(['name' => $name, 'taxonomy' => $taxonomy]);
    }

    public function test_a_term_names_itself_and_never_collides(): void
    {
        $first = $this->term('Party ideas');
        $second = $this->term('Party ideas');
        $tag = $this->term('Party ideas', Term::TAG);

        $this->assertSame('party-ideas', $first->slug);
        $this->assertNotSame('party-ideas', $second->slug, 'Two categories cannot share an address.');
        $this->assertSame('party-ideas', $tag->slug, 'A tag and a category may, because they live under different prefixes.');
        $this->assertSame('/blog/category/party-ideas', $first->publicPath());
        $this->assertSame('/blog/tag/party-ideas', $tag->publicPath());
    }

    public function test_a_category_page_lists_its_live_articles_and_nothing_else(): void
    {
        $ideas = $this->term('Party ideas');
        $news = $this->term('News');

        $filed = Post::factory()->published()->create(['title' => 'Ten party games']);
        $filed->terms()->attach($ideas);

        $elsewhere = Post::factory()->published()->create(['title' => 'We are hiring']);
        $elsewhere->terms()->attach($news);

        $draft = Post::factory()->create(['title' => 'Still writing']);
        $draft->terms()->attach($ideas);

        $this->get('/blog/category/party-ideas')
            ->assertOk()
            ->assertSee('Party ideas')
            ->assertSee('Ten party games')
            ->assertDontSee('We are hiring')
            ->assertDontSee('Still writing');

        $this->get('/blog/category/nothing-here')->assertNotFound();
    }

    public function test_a_tag_page_works_the_same_way_and_the_article_links_to_both(): void
    {
        $tag = $this->term('foam', Term::TAG);
        $category = $this->term('Party ideas');

        $post = Post::factory()->published()->create(['slug' => 'foam-fun', 'title' => 'Foam fun']);
        $post->terms()->attach([$tag->getKey(), $category->getKey()]);

        $this->get('/blog/tag/foam')->assertOk()->assertSee('Foam fun');

        $this->get('/blog/foam-fun')
            ->assertOk()
            ->assertSee(url('/blog/tag/foam'), false)
            ->assertSee(url('/blog/category/party-ideas'), false)
            ->assertSee('#foam');
    }

    public function test_the_index_offers_only_categories_that_have_something_in_them(): void
    {
        $used = $this->term('Party ideas');
        $this->term('Empty shelf');

        Post::factory()->published()->create()->terms()->attach($used);

        $this->get('/blog')
            ->assertOk()
            ->assertSee('Party ideas')
            ->assertDontSee('Empty shelf');

        $this->assertCount(1, app(BlogRepository::class)->termsInUse());
    }

    public function test_related_articles_prefer_the_ones_sharing_most_and_fall_back_to_the_recent(): void
    {
        $ideas = $this->term('Party ideas');
        $foam = $this->term('foam', Term::TAG);

        $subject = Post::factory()->published()->create(['title' => 'The subject']);
        $subject->terms()->attach([$ideas->getKey(), $foam->getKey()]);

        $both = Post::factory()->published()->create(['title' => 'Shares both']);
        $both->terms()->attach([$ideas->getKey(), $foam->getKey()]);

        $one = Post::factory()->published()->create(['title' => 'Shares one']);
        $one->terms()->attach($ideas);

        $unrelated = Post::factory()->published()->create(['title' => 'Shares nothing']);

        $related = app(BlogRepository::class)->related($subject);

        $this->assertSame(['Shares both', 'Shares one', 'Shares nothing'], $related->pluck('title')->all());
        $this->assertFalse($related->contains($subject), 'An article is never related to itself.');
        $this->assertTrue($related->contains($unrelated), 'With nothing better, the most recent fill the gap.');
    }

    public function test_an_article_with_no_terms_still_gets_suggestions(): void
    {
        Post::factory()->published()->create(['title' => 'Another one']);
        $lonely = Post::factory()->published()->create(['title' => 'Alone']);

        $this->assertSame(['Another one'], app(BlogRepository::class)->related($lonely)->pluck('title')->all());
    }

    public function test_the_article_form_files_it_under_categories_and_tags_without_losing_either(): void
    {
        $ideas = $this->term('Party ideas');
        $news = $this->term('News');
        $foam = $this->term('foam', Term::TAG);
        $post = Post::factory()->create();

        Livewire::actingAs($this->editor())
            ->test(EditPost::class, ['record' => $post->getKey()])
            ->fillForm(['category_ids' => [$ideas->getKey()], 'tag_ids' => [$foam->getKey()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Party ideas'], $post->categories()->pluck('name')->all());
        $this->assertSame(['foam'], $post->tags()->pluck('name')->all());

        Livewire::actingAs($this->editor())
            ->test(EditPost::class, ['record' => $post->getKey()])
            ->assertFormSet(['category_ids' => [$ideas->getKey()], 'tag_ids' => [$foam->getKey()]])
            ->fillForm(['category_ids' => [$news->getKey()]])
            ->call('save');

        $this->assertSame(['News'], $post->categories()->pluck('name')->all());
        $this->assertSame(['foam'], $post->tags()->pluck('name')->all(), 'Changing the categories must not drop the tags.');
    }

    public function test_only_someone_who_may_write_articles_can_manage_the_terms(): void
    {
        $this->term('Party ideas');

        Livewire::actingAs($this->editor())->test(ListTerms::class)->assertCanSeeTableRecords(Term::all());

        $this->actingAs($this->visitor())->get(TermResource::getUrl())->assertForbidden();
    }
}
