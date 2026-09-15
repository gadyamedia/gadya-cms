<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Filament\Resources\Pages\PageResource;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Tests\Fixtures\User;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\File;

class InstallCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        File::delete(config_path('gadya-cms.php'));

        parent::tearDown();
    }

    public function test_one_command_takes_a_fresh_application_to_a_working_site(): void
    {
        $this->artisan('gadya-cms:install', [
            '--admin-name' => 'Ada',
            '--admin-email' => 'ada@example.com',
            '--admin-password' => 'a-long-enough-password',
        ])->assertSuccessful();

        $this->assertFileExists(config_path('gadya-cms.php'));
        $this->assertSame(4, Page::query()->count());
        $this->assertNotNull(Page::query()->where('slug', 'home')->value('published'));
        $this->assertSame('admin', User::query()->where('email', 'ada@example.com')->value('role'));
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->artisan('gadya-cms:install', ['--no-admin' => true])->assertSuccessful();

        Page::query()->where('slug', 'about')->update(['title' => 'Edited since']);

        $this->artisan('gadya-cms:install', ['--no-admin' => true])
            ->expectsOutputToContain('already present')
            ->assertSuccessful();

        $this->assertSame('Edited since', Page::query()->where('slug', 'about')->value('title'));
        $this->assertSame(0, User::query()->count());
    }

    public function test_the_page_fields_come_from_configuration(): void
    {
        config(['gadya-cms.pages.content_fields' => [
            'strapline' => ['label' => 'Strapline', 'type' => 'text', 'max' => 80],
            'body' => ['type' => 'textarea'],
            'cover' => ['label' => 'Cover', 'type' => 'image'],
        ]]);
        $this->publishDocument();
        $page = Page::query()->where('slug', 'about')->firstOrFail();

        $this->actingAs($this->editor())
            ->get(PageResource::getUrl('edit', ['record' => $page]))
            ->assertOk()
            ->assertSee('Strapline')
            ->assertSee('Body')
            ->assertSee('Cover')
            ->assertDontSee('Button text');
    }
}
