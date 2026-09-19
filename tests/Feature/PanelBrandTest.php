<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\PanelBrand;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Content\SiteTheme;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Services\PublishSiteContent;
use Gadya\Cms\Services\UpdateTheme;
use Gadya\Cms\Tests\TestCase;

class PanelBrandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /* A site with a palette of its own, as every current site has. */
        config(['site.theme' => [
            'colors' => ['primary' => '#9f12c7', 'secondary' => '#f54fa3', 'background' => '#fffdf3', 'ink' => '#000000', 'accent' => '#f0e56c'],
            'fonts' => ['display' => 'Yeseva One', 'sans' => 'Lato'],
        ]]);
    }

    public function test_the_panel_wears_the_colours_and_type_the_client_chose(): void
    {
        $this->publishDocument();

        app(UpdateTheme::class)->handle(
            ['primary' => '#123456', 'secondary' => '#654321'] + $this->defaultColors(),
            ['display' => 'Lobster', 'sans' => 'Lato'],
        );

        $this->publishTheDraft();

        $tokens = app(PanelBrand::class)->tokens();

        $this->assertSame('#123456', $tokens['primary']);
        $this->assertSame('#654321', $tokens['secondary']);
        $this->assertSame('Lobster', $tokens['displayFont']);
        $this->assertStringContainsString('lobster', (string) $tokens['fontStylesheet'], 'The font is loaded for the sign-in screen too.');
    }

    public function test_the_logo_the_client_picked_is_the_one_on_the_sign_in_screen(): void
    {
        $this->publishDocument();

        app(UpdateTheme::class)->handle($this->defaultColors(), ['display' => 'Lobster', 'sans' => 'Lato'], 'signage.webp');

        $this->publishTheDraft();

        $this->assertSame('signage.webp', app(PanelBrand::class)->logo());
        $this->assertStringContainsString('signage.webp', (string) GadyaCmsPlugin::brandLogo());
    }

    public function test_a_site_that_wants_its_own_panel_keeps_the_configured_brand(): void
    {
        config([
            'gadya-cms.brand.follow_site' => false,
            'gadya-cms.brand.primary' => '#ff0000',
            'gadya-cms.brand.logo' => 'configured.webp',
            'gadya-cms.brand.fonts.display' => 'Georgia',
        ]);

        app(UpdateTheme::class)->handle(
            ['primary' => '#123456'] + $this->defaultColors(),
            ['display' => 'Lobster', 'sans' => 'Lato'],
            'signage.webp',
        );

        $this->publishTheDraft();

        $tokens = app(PanelBrand::class)->tokens();

        $this->assertSame('#ff0000', $tokens['primary']);
        $this->assertSame('Georgia', $tokens['displayFont']);
        $this->assertSame('configured.webp', app(PanelBrand::class)->logo());
    }

    public function test_the_panel_keeps_the_live_look_until_the_client_publishes(): void
    {
        $this->publishDocument();

        app(UpdateTheme::class)->handle(
            ['primary' => '#123456'] + $this->defaultColors(),
            ['display' => 'Lobster', 'sans' => 'Lato'],
            'signage.webp',
        );

        $this->assertSame('#9f12c7', app(PanelBrand::class)->tokens()['primary'], 'A draft nobody published paints nothing.');
        $this->assertNull(app(PanelBrand::class)->logo());
    }

    /** As if the client had pressed Publish changes. */
    private function publishTheDraft(): void
    {
        app(PublishSiteContent::class)->handle();
        app(SiteContentRepository::class)->flushPublishedCache();
    }

    /**
     * @return array<string, string>
     */
    private function defaultColors(): array
    {
        return app(SiteTheme::class)->defaults()['colors'];
    }
}
