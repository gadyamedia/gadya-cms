<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Analytics\BotDetector;
use Gadya\Cms\Analytics\VisitorFingerprint;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;

class BotDetectorTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function people(): array
    {
        $agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/126.0.6478.54 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/127.0 Mobile/15E148 Safari/605.1.15',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:127.0) Gecko/20100101 Firefox/127.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:127.0) Gecko/20100101 Firefox/127.0',
            'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0',
            'Mozilla/5.0 (Android 14; Mobile; rv:127.0) Gecko/127.0 Firefox/127.0',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0',
            'Mozilla/5.0 (Linux; Android 10; HD1913) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36 EdgA/126.0.0.0',
            'Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (Linux; Android 13; SM-A546B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Mobile Safari/537.36 OPR/82.0.0.0',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 OPR/111.0.0.0',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Vivaldi/6.8',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Brave/126',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 330.0.0.36.109 (iPhone14,5; iOS 17_5; en_US; en; scale=3.00; 1170x2532; 123456789)',
            'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP2A.240605.024; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0.6478.71 Mobile Safari/537.36 Instagram 330.0.0.40.97 Android (34/14; 420dpi; 1080x2400; Google/google; Pixel 8; shiba; shiba; en_US; 123456789)',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/465.0.0.49.113;FBBV/607652433;FBDV/iPhone14,5;FBMD/iPhone;FBSN/iOS;FBSV/17.5;FBSS/3;FBID/phone;FBLC/en_US;FBOP/5]',
            'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0.6478.71 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/465.0.0.46.108;]',
            'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 16_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Windows NT 6.1; WOW64; Trident/7.0; rv:11.0) like Gecko',
        ];

        return array_combine(array_map(fn (string $agent): string => substr($agent, 0, 90), $agents), array_map(fn (string $agent): array => [$agent], $agents));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function machines(): array
    {
        $agents = [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
            'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
            'Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)',
            'Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)',
            'DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)',
            'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X) (compatible; Google-InspectionTool/1.0)',
            'Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)',
            'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot',
            'OAI-SearchBot/1.0; +https://openai.com/searchbot',
            'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
            'Claude-Web/1.0',
            'PerplexityBot/1.0',
            'Mozilla/5.0 (compatible; Bytespider; spider-feedback@bytedance.com)',
            'CCBot/2.0 (https://commoncrawl.org/faq/)',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_5) AppleWebKit/605.1.15 (Applebot/0.1)',
            'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)',
            'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
            'Mozilla/5.0 (compatible; MJ12bot/v1.4.8; http://mj12bot.com/)',
            'Mozilla/5.0 (compatible; PetalBot;+https://webmaster.petalsearch.com/site/petalbot)',
            'Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 Chrome/109.0.0.0 Mobile Safari/537.36 Chrome-Lighthouse',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/126.0.0.0 Safari/537.36',
            'Mozilla/5.0 (compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)',
            'Pingdom.com_bot_version_1.4_(http://www.pingdom.com/)',
            'Mozilla/5.0 (compatible; Site24x7)',
            'Better Stack Uptime',
            'Datadog/Synthetics',
            'NewRelicPinger/1.0',
            'StatusCake_Pagespeed_Indev',
            'curl/8.4.0',
            'Wget/1.21.4',
            'python-requests/2.31.0',
            'Python-urllib/3.11',
            'Go-http-client/2.0',
            'Java/17.0.9',
            'okhttp/4.12.0',
            'axios/1.6.8',
            'node-fetch/1.0 (+https://github.com/bitinn/node-fetch)',
            'GuzzleHttp/7.8.1 curl/8.4.0 PHP/8.3.0',
            'PostmanRuntime/7.37.3',
            'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
            'WhatsApp/2.23.20.0 A',
            'TelegramBot (like TwitterBot)',
            'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)',
            'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)',
            'Twitterbot/1.0',
            'LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)',
            'Mozilla/5.0 (compatible; Pinterestbot/1.0; +http://www.pinterest.com/bot.html)',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Gadya-Portal/1.0 (uptime check)',
            'GadyaMedia',
        ];

        return array_combine(array_map(fn (string $agent): string => substr($agent, 0, 90), $agents), array_map(fn (string $agent): array => [$agent], $agents));
    }

    #[DataProvider('people')]
    public function test_an_ordinary_browser_is_not_a_bot(string $agent): void
    {
        $this->assertFalse(BotDetector::isBotAgent($agent), $agent);
    }

    #[DataProvider('machines')]
    public function test_a_machine_is_a_bot(string $agent): void
    {
        $this->assertTrue(BotDetector::isBotAgent($agent), $agent);
    }

    public function test_no_user_agent_is_a_bot(): void
    {
        $this->assertTrue(BotDetector::isBot(Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => ''])));
    }

    public function test_a_prefetch_is_a_bot_even_with_a_browser_agent(): void
    {
        $agent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1';

        foreach ([['HTTP_PURPOSE' => 'prefetch'], ['HTTP_PURPOSE' => 'prerender'], ['HTTP_SEC_PURPOSE' => 'prefetch;prerender']] as $headers) {
            $this->assertTrue(VisitorFingerprint::isBot(Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => $agent, ...$headers])));
        }

        $this->assertFalse(VisitorFingerprint::isBot(Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => $agent])));
    }

    public function test_a_site_can_add_its_own_patterns(): void
    {
        $this->assertFalse(BotDetector::isBotAgent('Mozilla/5.0 AcmeWatcher/1.0'));

        config(['gadya-cms.analytics.bot_patterns' => ['acmewatcher']]);

        $this->assertTrue(BotDetector::isBotAgent('Mozilla/5.0 AcmeWatcher/1.0'));
    }
}
