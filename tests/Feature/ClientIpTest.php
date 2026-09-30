<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Analytics\VisitorFingerprint;
use Gadya\Cms\Forms\Attribution;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Support\ClientIp;
use Gadya\Cms\Support\InstallAudit;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

class ClientIpTest extends TestCase
{
    private function request(string $peer, ?string $header = null, string $agent = 'Mozilla/5.0 (iPhone) Safari'): Request
    {
        return Request::create('/', 'GET', [], [], [], array_filter([
            'REMOTE_ADDR' => $peer,
            'HTTP_CF_CONNECTING_IP' => $header,
            'HTTP_USER_AGENT' => $agent,
        ]));
    }

    public function test_a_cloudflare_peer_with_the_header_gives_the_visitors_address(): void
    {
        $this->assertSame('198.51.100.7', ClientIp::for($this->request('172.70.1.1', '198.51.100.7')));
    }

    public function test_a_forged_header_from_a_non_cloudflare_peer_is_ignored(): void
    {
        $this->assertSame('203.0.113.50', ClientIp::for($this->request('203.0.113.50', '198.51.100.7')));
    }

    public function test_cloudflare_ipv6_peers_and_visitors_work(): void
    {
        $this->assertSame('2001:db8::1', ClientIp::for($this->request('2606:4700:10::1', '2001:db8::1')));
        $this->assertSame('2001:db8::5', ClientIp::for($this->request('2001:db8::5', '2001:db8::1')));
    }

    public function test_a_garbage_header_falls_back_to_the_peer(): void
    {
        $this->assertSame('172.70.1.1', ClientIp::for($this->request('172.70.1.1', 'not-an-ip')));
    }

    public function test_it_can_be_switched_off(): void
    {
        config(['gadya-cms.client_ip.trust_cloudflare' => false]);

        $this->assertSame('172.70.1.1', ClientIp::for($this->request('172.70.1.1', '198.51.100.7')));
    }

    public function test_extra_trusted_ranges_are_honoured(): void
    {
        $this->assertSame('10.1.2.3', ClientIp::for($this->request('10.1.2.3', '192.0.2.10')));

        config(['gadya-cms.client_ip.extra_trusted_ranges' => ['10.1.0.0/16']]);

        $this->assertSame('192.0.2.10', ClientIp::for($this->request('10.1.2.3', '192.0.2.10')));
    }

    public function test_two_visitors_behind_one_cloudflare_address_are_two_visitors_and_one_stays_one(): void
    {
        $first = VisitorFingerprint::hash($this->request('172.70.1.1', '198.51.100.1'));
        $second = VisitorFingerprint::hash($this->request('172.70.1.1', '198.51.100.2'));

        $this->assertNotSame($first, $second);
        $this->assertSame($first, VisitorFingerprint::hash($this->request('172.70.9.9', '198.51.100.1')));
    }

    public function test_the_forms_rate_limit_is_per_visitor_not_per_edge(): void
    {
        $limiter = RateLimiter::limiter('gadya-cms-forms');

        $this->assertSame('198.51.100.1', $limiter($this->request('172.70.1.1', '198.51.100.1'))->key);
        $this->assertNotSame(
            $limiter($this->request('172.70.1.1', '198.51.100.1'))->key,
            $limiter($this->request('172.70.1.1', '198.51.100.2'))->key,
        );
    }

    public function test_the_consent_record_holds_the_visitors_address(): void
    {
        Notification::fake();

        $this->from('/')->post(
            '/cms/forms/callback',
            ['name' => 'Pat', 'phone' => '+44 1273 000000', 'message' => 'Hello', 'consent' => '1'],
            ['REMOTE_ADDR' => '172.70.1.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'],
        );

        $this->assertSame('198.51.100.7', FormSubmission::query()->sole()->consent['ip']);
    }

    public function test_attribution_uses_the_visitors_address_and_masks_it_on_request(): void
    {
        $request = $this->request('172.70.1.1', '198.51.100.7');

        $this->assertSame('198.51.100.7', app(Attribution::class)->ip($request));

        config(['gadya-cms.forms.builder.attribution.ip' => 'masked']);

        $this->assertSame('198.51.100.0', app(Attribution::class)->ip($request));
    }

    public function test_the_audit_asks_for_the_header_only_behind_cloudflare_with_the_feature_off(): void
    {
        $status = fn (): string => collect(app(InstallAudit::class)->checks())->firstWhere('label', 'Visitors\' real addresses reach the site')['status'];

        $this->assertSame(InstallAudit::OK, $status());

        $this->app->instance('request', Request::create('/', 'GET', [], [], [], ['HTTP_CF_CONNECTING_IP' => '198.51.100.7']));
        $this->assertSame(InstallAudit::OK, $status());

        config(['gadya-cms.client_ip.trust_cloudflare' => false]);
        $this->assertSame(InstallAudit::OPTIONAL, $status());
    }
}
