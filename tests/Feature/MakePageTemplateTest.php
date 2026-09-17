<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\File;

class MakePageTemplateTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path('views/pages'));

        parent::tearDown();
    }

    public function test_it_scaffolds_a_template_with_every_content_field_editable(): void
    {
        $this->artisan('gadya-cms:make:page-template', ['name' => 'pages/types/menu', '--layout' => 'layouts.site'])
            ->assertSuccessful();

        $template = (string) File::get(resource_path('views/pages/types/menu.blade.php'));

        $this->assertStringContainsString("@extends('layouts.site')", $template);
        $this->assertStringContainsString('@editableFor("pages.{$slug}")', $template);
        $this->assertStringContainsString("<h1 class=\"page-heading\" @editable('heading')>", $template);
        $this->assertStringContainsString("@editable('description', 'multiline')", $template);
        $this->assertStringContainsString("@siteImage(\$page['hero_image'] ?? '')", $template);
        $this->assertStringContainsString('@editable("sections.{$index}.items.{$itemIndex}.title")', $template);
    }

    public function test_it_refuses_to_overwrite_without_force_and_warns_about_fields_the_editor_ignores(): void
    {
        config(['gadya-cms.pages.content_fields.strapline' => ['type' => 'text']]);

        $this->artisan('gadya-cms:make:page-template', ['name' => 'pages.show'])
            ->expectsOutputToContain('strapline')
            ->assertSuccessful();

        $this->artisan('gadya-cms:make:page-template', ['name' => 'pages.show'])->assertFailed();
        $this->artisan('gadya-cms:make:page-template', ['name' => 'pages.show', '--force' => true])->assertSuccessful();
    }
}
