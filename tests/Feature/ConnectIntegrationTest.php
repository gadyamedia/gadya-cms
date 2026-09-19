<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Support\InstallAudit;
use Gadya\Cms\Tests\TestCase;
use Gadya\Connect\Models\Connection;

/** gadya/connect comes with Gadya CMS: Get help and pairing on every site. */
class ConnectIntegrationTest extends TestCase
{
    public function test_every_panel_offers_get_help_and_gadya_support(): void
    {
        $this->actingAs($this->administrator());

        $this->get('/admin/get-help')
            ->assertOk()
            ->assertSee('Get help from Gadya Media')
            ->assertSee('help@support.gadya.media');

        $this->get('/admin/gadya-support')->assertOk()->assertSee('Connect to Gadya Media');
    }

    public function test_the_audit_says_whether_the_site_is_paired(): void
    {
        $check = fn (): array => collect(app(InstallAudit::class)->checks())->firstWhere('label', 'Connected to the Gadya Media portal');

        $this->assertSame(InstallAudit::OPTIONAL, $check()['status']);

        Connection::query()->create(['site_id' => 1, 'portal_url' => 'https://portal.test', 'secret' => 'secret']);

        $this->assertSame(InstallAudit::OK, $check()['status']);
    }
}
