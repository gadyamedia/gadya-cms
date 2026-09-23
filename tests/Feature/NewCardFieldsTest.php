<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Testing\TestResponse;

/**
 * "Add item" puts a card on the page with a title alone. The client then
 * has to be able to give it a description and a photo, which are not in
 * the document until she does.
 */
class NewCardFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument([
            'pages' => ['rentals' => [
                'title' => 'Rentals',
                'sections' => [['title' => 'Soft play', 'type' => 'cards', 'items' => [['title' => 'Bubble']]]],
            ]],
        ]);

        $this->actingAs($this->editor());
    }

    public function test_a_new_card_can_be_given_a_description_and_a_photo(): void
    {
        $this->save('pages.rentals.sections.0.items.0.text', 'Bubble house with slide')->assertOk();
        $this->save('pages.rentals.sections.0.items.0.image', 'bubble.jpg')->assertOk();

        $this->assertSame(
            ['title' => 'Bubble', 'text' => 'Bubble house with slide', 'image' => 'bubble.jpg'],
            app(SiteContentRepository::class)->draft()['pages']['rentals']['sections'][0]['items'][0],
        );
    }

    public function test_a_card_that_does_not_exist_is_still_refused(): void
    {
        $this->save('pages.rentals.sections.0.items.5.text', 'Nowhere')->assertUnprocessable();

        $this->assertCount(1, app(SiteContentRepository::class)->draft()['pages']['rentals']['sections'][0]['items']);
    }

    public function test_a_page_never_gains_a_field_it_does_not_have(): void
    {
        $this->save('pages.rentals.heading', 'Hello')->assertUnprocessable();
    }

    private function save(string $path, string $value): TestResponse
    {
        return $this->withSession([EditContext::SESSION_KEY => true])
            ->postJson(route('gadya-cms.inline.update'), ['path' => $path, 'value' => $value]);
    }
}
