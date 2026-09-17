<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Tests\TestCase;

/**
 * The docs are copied into the Boost skill so an agent working on a site
 * has them without leaving the skill. A copy drifts, so this holds the
 * two identical; `composer sync-docs` refreshes the copy.
 */
class BoostSkillTest extends TestCase
{
    public function test_the_skill_carries_an_identical_copy_of_every_doc(): void
    {
        $docs = glob(__DIR__.'/../../docs/*.md') ?: [];
        $this->assertNotEmpty($docs);

        foreach ($docs as $doc) {
            $reference = __DIR__.'/../../resources/boost/skills/gadya-cms-development/references/'.basename($doc);

            $this->assertFileExists($reference, basename($doc).' is missing from the skill; run composer sync-docs.');
            $this->assertSame(file_get_contents($doc), file_get_contents($reference), basename($doc).' differs from the skill copy; run composer sync-docs.');
        }
    }

    public function test_every_reference_is_listed_in_the_skill(): void
    {
        $skill = (string) file_get_contents(__DIR__.'/../../resources/boost/skills/gadya-cms-development/SKILL.md');

        foreach (glob(__DIR__.'/../../docs/*.md') ?: [] as $doc) {
            $this->assertStringContainsString('references/'.basename($doc), $skill);
        }
    }
}
