<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Models\Option;
use Gadya\Cms\Options\Options;
use Gadya\Cms\Tests\TestCase;

class OptionsTest extends TestCase
{
    public function test_a_value_round_trips_and_a_missing_one_falls_back(): void
    {
        $options = app(Options::class);

        $options->set('digest.recipients', ['a@example.com']);

        $this->assertSame(['a@example.com'], $options->get('digest.recipients'));
        $this->assertSame('nope', $options->get('missing', 'nope'));
    }

    public function test_a_secret_is_encrypted_at_rest_and_readable_through_the_service(): void
    {
        $options = app(Options::class);

        $options->setSecret('ai.key', 'sk-ant-secret');

        $row = Option::query()->where('key', 'ai.key')->firstOrFail();

        $this->assertStringNotContainsString('sk-ant-secret', json_encode($row->value));
        $this->assertSame('sk-ant-secret', $options->getSecret('ai.key'));
    }

    public function test_forgetting_a_key_leaves_nothing_readable(): void
    {
        $options = app(Options::class);

        $options->setSecret('ai.key', 'sk-ant-secret');
        $options->forget('ai.key');

        $this->assertNull($options->getSecret('ai.key'));
        $this->assertNull($options->get('ai.key'));
    }

    public function test_a_secret_saved_under_a_different_application_key_reads_as_absent(): void
    {
        $options = app(Options::class);
        $options->setSecret('ai.key', 'sk-ant-secret');

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->app->forgetInstance('encrypter');
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();

        $this->assertNull(app(Options::class)->getSecret('ai.key'));
    }
}
