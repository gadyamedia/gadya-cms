<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Support\PackageConfig;
use Gadya\Cms\Tests\TestCase;
use Orchestra\Testbench\Attributes\WithConfig;

/**
 * A site's published config/gadya-cms.php, written for an older release,
 * over the package's defaults - the way the application boots: the site's
 * file is loaded before the package registers.
 */
#[WithConfig('gadya-cms', [
    'panel' => 'office',
    'seo' => [
        'site_name' => 'Fun On Us',
        'robots_disallow' => ['/private'],
        'organization' => ['telephone' => '+1 973 555 0100'],
        'content_signals' => ['search' => 'yes'],
    ],
    'locales' => ['enabled' => ['es']],
    'media' => ['variants' => [800]],
    'users' => ['roles' => ['owner' => ['label' => 'Owner', 'abilities' => ['*']]], 'admin_role' => 'owner'],
    'forms' => ['forms' => ['booking' => ['label' => 'Booking', 'fields' => ['name' => ['required']]]]],
    'globals' => [],
    'editable_fields' => ['announcement' => 'text'],
    'analytics' => ['world_map' => null, 'events' => []],
    'blog' => ['comments' => ['enabled' => true]],
], defer: false)]
class ConfigMergeTest extends TestCase
{
    public function test_the_sites_values_win_and_keys_it_never_had_take_the_package_defaults(): void
    {
        $defaults = require __DIR__.'/../../config/gadya-cms.php';

        $this->assertSame('office', config('gadya-cms.panel'));
        $this->assertSame('Fun On Us', config('gadya-cms.seo.site_name'));
        $this->assertSame('+1 973 555 0100', config('gadya-cms.seo.organization.telephone'));
        $this->assertSame($defaults['seo']['organization']['anchor'], config('gadya-cms.seo.organization.anchor'), 'A nested key the site never copied has its default.');
        $this->assertSame($defaults['seo']['discovery'], config('gadya-cms.seo.discovery'));
        $this->assertSame($defaults['seo']['mcp'], config('gadya-cms.seo.mcp'), 'A whole nested array the site never copied has its default.');
        $this->assertSame($defaults['portal'], config('gadya-cms.portal'), 'A top-level key the site never copied has its default.');
        $this->assertTrue(config('gadya-cms.blog.comments.enabled'));
        $this->assertTrue(config('gadya-cms.blog.comments.moderate'));
        $this->assertSame('owner', config('gadya-cms.users.admin_role'));
        $this->assertSame($defaults['users']['default_role'], config('gadya-cms.users.default_role'));
    }

    public function test_lists_are_the_sites_whole_answer(): void
    {
        $this->assertSame(['es'], config('gadya-cms.locales.enabled'), 'The site\'s languages are not mixed with the package\'s "en".');
        $this->assertSame('en', config('gadya-cms.locales.default'));
        $this->assertSame([800], config('gadya-cms.media.variants'));
        $this->assertSame(['/private'], config('gadya-cms.seo.robots_disallow'));
        $this->assertSame([], config('gadya-cms.analytics.events'), 'An empty list is the site saying "none".');
        $this->assertNull(config('gadya-cms.analytics.world_map'));
    }

    public function test_maps_the_site_owns_are_taken_whole(): void
    {
        $this->assertSame(['owner' => ['label' => 'Owner', 'abilities' => ['*']]], config('gadya-cms.users.roles'), 'No package roles are added to the site\'s.');
        $this->assertSame(['booking'], array_keys(config('gadya-cms.forms.forms')), 'The example contact form is not added.');
        $this->assertSame([], config('gadya-cms.globals'));
        $this->assertSame(['announcement' => 'text'], config('gadya-cms.editable_fields'), 'Nothing becomes editable that the site did not list.');
        $this->assertSame(['search' => 'yes'], config('gadya-cms.seo.content_signals'));
        $this->assertSame((require __DIR__.'/../../config/gadya-cms.php')['fonts'], config('gadya-cms.fonts'), 'Unset, they keep the package\'s entries.');
    }

    public function test_merging_rules(): void
    {
        $defaults = [
            'on' => true,
            'nested' => ['a' => 1, 'b' => ['c' => 2, 'd' => 3]],
            'list' => ['x', 'y'],
            'map_or_list' => ['k' => 'v'],
            'users' => ['roles' => ['admin' => 'Admin'], 'gate' => 'manage-users'],
            'numbered' => [480 => 'small', 960 => 'large'],
        ];

        $this->assertSame([
            'on' => false,
            'nested' => ['a' => 1, 'b' => ['c' => 20, 'd' => 3]],
            'list' => ['z'],
            'map_or_list' => [],
            'users' => ['roles' => ['boss' => 'Boss'], 'gate' => 'manage-users'],
            'numbered' => [480 => 'small', 960 => 'wide'],
            'extra' => 'kept',
        ], PackageConfig::merge($defaults, [
            'on' => false,
            'nested' => ['b' => ['c' => 20]],
            'list' => ['z'],
            'map_or_list' => [],
            'users' => ['roles' => ['boss' => 'Boss']],
            'numbered' => [960 => 'wide'],
            'extra' => 'kept',
        ]));

        $this->assertSame(['nested' => 'off'], PackageConfig::merge(['nested' => ['a' => 1]], ['nested' => 'off']), 'A value in place of an array replaces it.');
        $this->assertSame(['list' => ['a' => 1]], PackageConfig::merge(['list' => ['x']], ['list' => ['a' => 1]]));
    }
}
