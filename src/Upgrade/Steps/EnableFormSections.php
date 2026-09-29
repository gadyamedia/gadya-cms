<?php

namespace Gadya\Cms\Upgrade\Steps;

use Gadya\Cms\Upgrade\SectionLoops;
use Gadya\Cms\Upgrade\UpgradeSteps;

/**
 * Lets clients put a form on any page: adds `@cmsSection($section, $index)`
 * as the first line inside each loop over a page's sections, so a "Form"
 * section is drawn by the package and everything else by the site, as
 * before. Only loops written the ordinary way are touched; the audit
 * names any it could not.
 */
class EnableFormSections
{
    public function __construct(private readonly SectionLoops $loops) {}

    public function key(): string
    {
        return 'cms.form-sections';
    }

    public function description(): string
    {
        return 'Let the page sections draw forms (@cmsSection in the section loop)';
    }

    public function phase(): string
    {
        return UpgradeSteps::CODE;
    }

    public function shouldRun(): bool
    {
        return config('gadya-cms.forms.builder.sections', true)
            && collect($this->loops->find())->contains(fn (array $loop): bool => ! $loop['wired']);
    }

    public function run(): string
    {
        $changed = $this->loops->wire();

        return $changed === []
            ? 'Nothing to change.'
            : 'Added @cmsSection to the section loop in '.implode(', ', array_map(fn (string $file): string => str_replace(base_path().'/', '', $file), $changed)).'.';
    }
}
