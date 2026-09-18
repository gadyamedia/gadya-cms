<?php

namespace Workbench\Database\Seeders;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\Comment;
use Gadya\Cms\Models\Event;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Models\PageView;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Models\Redirect;
use Gadya\Cms\Models\Subscriber;
use Gadya\Cms\Models\Term;
use Gadya\Cms\Services\PublishSiteContent;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Workbench\App\Models\User;

/**
 * A lived-in site: pages published, a few articles, enquiries in the
 * inbox, a month of visitors, and photos drawn on the spot so nothing
 * has to be downloaded.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $siteId = app(SiteContext::class)->id();

        User::query()->updateOrCreate(['email' => 'admin@example.com'], ['name' => 'Ada Admin', 'password' => 'password', 'role' => 'admin']);
        User::query()->updateOrCreate(['email' => 'editor@example.com'], ['name' => 'Erin Editor', 'password' => 'password', 'role' => 'editor']);
        User::query()->updateOrCreate(['email' => 'writer@example.com'], ['name' => 'Cal Contributor', 'password' => 'password', 'role' => 'contributor']);

        $this->photos($siteId);

        $repository = app(SiteContentRepository::class);
        $repository->saveDraft($repository->defaults());
        app(PublishSiteContent::class)->handle(User::query()->first(), 'Demo site');

        $this->articles($siteId);
        $this->events($siteId);
        $this->enquiries($siteId);
        $this->subscribers($siteId);
        $this->traffic($siteId);

        Redirect::query()->updateOrCreate(['site_id' => $siteId, 'from_path' => '/parties'], ['to_path' => '/birthday-parties', 'hits' => 42, 'last_hit_at' => now()->subHours(3)]);

        $this->command?->info('Demo site ready. Sign in at /admin as admin@example.com / password (or editor@ / writer@).');
    }

    private function photos(?int $siteId): void
    {
        $disk = Storage::disk((string) config('gadya-cms.media.disk', 'public'));
        $manager = new ImageManager(new Driver);
        $directory = (string) config('gadya-cms.media.directory', 'site-media');

        $photos = [
            'hero.jpg' => ['#0f766e', 'Springfield Parties'],
            'castle.jpg' => ['#f97316', 'Bouncy castle'],
            'entertainer.jpg' => ['#7c3aed', 'Entertainer'],
            'facepaint.jpg' => ['#db2777', 'Face painting'],
            'party-1.jpg' => ['#0ea5e9', 'Party'],
            'party-2.jpg' => ['#22c55e', 'Party'],
            'party-3.jpg' => ['#eab308', 'Party'],
            'cake.jpg' => ['#ef4444', 'Cake'],
        ];

        foreach ($photos as $filename => [$colour, $label]) {
            $base = Str::beforeLast($filename, '.');
            $image = $manager->create(1600, 1000)->fill($colour)->text($label, 800, 500, function ($font) {
                $font->size(64);
                $font->color('#ffffff');
                $font->align('center');
                $font->valign('middle');
            });

            $disk->put("{$directory}/{$base}.webp", (string) $image->toWebp(80));
            $disk->put("{$directory}/thumbnails/{$base}.webp", (string) $manager->create(400, 250)->fill($colour)->toWebp(70));
            $disk->put("{$directory}/variants/{$base}-480.webp", (string) $manager->create(480, 300)->fill($colour)->toWebp(75));
            $disk->put("{$directory}/variants/{$base}-960.webp", (string) $manager->create(960, 600)->fill($colour)->toWebp(78));

            Media::query()->updateOrCreate(['filename' => $filename], [
                'site_id' => $siteId,
                'original_name' => $filename,
                'disk' => (string) config('gadya-cms.media.disk', 'public'),
                'path' => "{$directory}/{$base}.webp",
                'thumbnail_path' => "{$directory}/thumbnails/{$base}.webp",
                'variants' => ['480' => "{$directory}/variants/{$base}-480.webp", '960' => "{$directory}/variants/{$base}-960.webp"],
                'mime_type' => 'image/webp',
                'width' => 1600,
                'height' => 1000,
                'size' => $disk->size("{$directory}/{$base}.webp"),
                'alt_text' => $label,
                'folder' => str_starts_with($filename, 'party') ? 'Last weekend' : null,
                'status' => Media::STATUS_READY,
            ]);
        }
    }

    private function events(?int $siteId): void
    {
        foreach ([
            ['Summer open day', 'summer-open-day', now()->addWeeks(2)->setTime(10, 0), now()->addWeeks(2)->setTime(16, 0), false, 'Free, no need to book'],
            ['Half term soft play camp', 'half-term-camp', now()->addMonth()->setTime(9, 30), now()->addMonth()->addDays(2)->setTime(15, 0), false, '£95 for the three days'],
            ['Springfield summer fair', 'summer-fair', now()->addMonths(2)->startOfDay(), null, true, 'Free'],
            ['Last winter’s party', 'last-winter', now()->subMonths(3)->setTime(14, 0), now()->subMonths(3)->setTime(17, 0), false, 'Free'],
        ] as [$title, $slug, $starts, $ends, $allDay, $price]) {
            Event::query()->updateOrCreate(['site_id' => $siteId, 'slug' => $slug], [
                'title' => $title,
                'summary' => 'Come along - bouncy castles, face painting and a very patient dinosaur.',
                'body' => '<p>Everything we do, in one afternoon, with the kettle on for the grown-ups.</p>',
                'image' => 'party-1.jpg',
                'hero_alt' => 'Children at a party',
                'starts_at' => $starts,
                'ends_at' => $ends,
                'all_day' => $allDay,
                'location' => '12 Evergreen Terrace, Springfield',
                'price' => $price,
                'booking_url' => 'https://example.test/book',
                'status' => Event::STATUS_PUBLISHED,
            ]);
        }
    }

    private function subscribers(?int $siteId): void
    {
        foreach (['pat@example.com' => 'Pat Morgan', 'sam@example.com' => 'Sam Lee', 'jo@example.com' => null] as $email => $name) {
            Subscriber::query()->updateOrCreate(['site_id' => $siteId, 'email' => $email], [
                'name' => $name,
                'source' => '/contact',
                'status' => Subscriber::SUBSCRIBED,
            ]);
        }
    }

    private function articles(?int $siteId): void
    {
        $body = fn (string $topic): string => "<h2>Why {$topic} matters</h2><p>".Str::repeat('Every party needs a plan, and this is the part most people skip. ', 12)."</p><h2>What we do differently</h2><p>See our <a href=\"/pricing\">pricing</a> and <a href=\"/birthday-parties\">birthday parties</a>.</p><h2>Is {$topic} worth it?</h2><p>Yes. ".Str::repeat('Here is why. ', 40).'</p>';

        foreach ([
            ['How to plan a birthday party without losing your mind', 'plan-a-birthday-party', 'planning', now()->subDays(20), 'published', 'cake.jpg'],
            ['Bouncy castle safety: what every parent should know', 'bouncy-castle-safety', 'safety', now()->subDays(9), 'published', 'castle.jpg'],
            ['Face painting ideas for a rainy afternoon', 'face-painting-ideas', 'face painting', now()->addDays(6), 'published', 'facepaint.jpg'],
            ['Our summer schedule', 'summer-schedule', 'the schedule', null, 'draft', null],
        ] as [$title, $slug, $topic, $date, $status, $image]) {
            Post::query()->updateOrCreate(['site_id' => $siteId, 'slug' => $slug], [
                'title' => $title,
                'excerpt' => "Everything we know about {$topic}, in five minutes.",
                'content' => $body($topic),
                'image' => $image,
                'hero_alt' => $image ? Str::headline(Str::beforeLast($image, '.')) : null,
                'meta_title' => Str::limit($title, 58, ''),
                'meta_description' => Str::limit("Everything we know about {$topic} after a thousand parties in Springfield: what works, what to skip, and what to book early.", 158, ''),
                'faq' => [['question' => "How early should I book {$topic}?", 'answer' => 'Six weeks ahead in summer, two in winter.'], ['question' => 'Do you travel?', 'answer' => 'Anywhere within thirty minutes of Springfield.'], ['question' => 'What if it rains?', 'answer' => 'We move indoors or move the date, your choice.']],
                'reading_time' => '5 min read',
                'target_keyword' => $topic,
                'status' => $status,
                'published_at' => $date,
                'source' => $slug === 'summer-schedule' ? 'ai' : 'manual',
            ]);
        }

        /* Two shelves and a few words they share, so the archives have something on them. */
        $categories = collect(['Party ideas', 'Safety', 'News'])->mapWithKeys(fn (string $name): array => [
            $name => Term::query()->updateOrCreate(['site_id' => $siteId, 'taxonomy' => Term::CATEGORY, 'slug' => Str::slug($name)], ['name' => $name]),
        ]);

        $tags = collect(['bouncy castles', 'face painting', 'planning'])->mapWithKeys(fn (string $name): array => [
            $name => Term::query()->updateOrCreate(['site_id' => $siteId, 'taxonomy' => Term::TAG, 'slug' => Str::slug($name)], ['name' => $name]),
        ]);

        $filing = [
            'plan-a-birthday-party' => ['Party ideas', 'planning'],
            'bouncy-castle-safety' => ['Safety', 'bouncy castles'],
            'face-painting-ideas' => ['Party ideas', 'face painting'],
        ];

        foreach ($filing as $slug => $names) {
            $post = Post::query()->where('site_id', $siteId)->where('slug', $slug)->first();

            $post?->terms()->sync(collect($names)->map(fn (string $name) => ($categories[$name] ?? $tags[$name])->getKey())->all());
        }

        /* A comment waiting, and one already showing. */
        $post = Post::query()->where('site_id', $siteId)->where('slug', 'plan-a-birthday-party')->first();

        if ($post !== null) {
            Comment::query()->updateOrCreate(['site_id' => $siteId, 'post_id' => $post->getKey(), 'author_name' => 'Jo Rivers'], [
                'author_email' => 'jo@example.com',
                'body' => 'The six-week rule saved us. Booked in March for a July party and got the castle we wanted.',
                'status' => Comment::APPROVED,
                'approved_at' => now()->subDays(4),
            ]);

            Comment::query()->updateOrCreate(['site_id' => $siteId, 'post_id' => $post->getKey(), 'author_name' => 'Alex Doyle'], [
                'author_email' => 'alex@example.com',
                'body' => 'Do you do anything for teenagers, or is it all little ones?',
                'status' => Comment::PENDING,
            ]);
        }
    }

    private function enquiries(?int $siteId): void
    {
        foreach ([
            ['Pat Morgan', 'pat@example.com', 'Can you do Saturday 14th for twenty six-year-olds?', 'new', 2],
            ['Sam Lee', 'sam@example.com', 'Do you have a smaller castle for a flat garden?', 'new', 26],
            ['Jordan Price', 'jordan@example.com', 'School fete in June - what would the whole works cost for 200 kids?', 'read', 60],
        ] as [$name, $email, $message, $status, $hoursAgo]) {
            FormSubmission::query()->create([
                'site_id' => $siteId,
                'form' => 'contact',
                'data' => ['name' => $name, 'email' => $email, 'message' => $message],
                'path' => '/contact',
                'country' => 'US',
                'status' => $status,
                'created_at' => now()->subHours($hoursAgo),
            ]);
        }
    }

    private function traffic(?int $siteId): void
    {
        $paths = ['/' => 40, '/birthday-parties' => 25, '/pricing' => 20, '/contact' => 10, '/blog/plan-a-birthday-party' => 15, '/school-events' => 6];
        $referrers = [null, null, null, 'google.com', 'google.com', 'facebook.com', 'instagram.com'];
        $countries = ['US', 'US', 'US', 'CA', 'GB'];

        for ($day = 29; $day >= 0; $day--) {
            $visitors = 6 + ($day % 7 === 0 || $day % 7 === 1 ? 10 : 0) + random_int(0, 6);

            for ($v = 0; $v < $visitors; $v++) {
                $hash = hash('sha256', "{$day}-{$v}");
                $views = random_int(1, 3);

                for ($i = 0; $i < $views; $i++) {
                    PageView::query()->create([
                        'site_id' => $siteId,
                        'path' => $this->weighted($paths),
                        'visitor_hash' => $hash,
                        'referrer_host' => $i === 0 ? $referrers[array_rand($referrers)] : null,
                        'device_category' => random_int(0, 9) < 7 ? 'phone' : 'desktop',
                        'country' => $countries[array_rand($countries)],
                        'city' => 'Springfield',
                        'viewed_at' => now()->subDays($day)->setTime(random_int(8, 21), random_int(0, 59)),
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<string, int>  $weights
     */
    private function weighted(array $weights): string
    {
        $pick = random_int(1, (int) array_sum($weights));

        foreach ($weights as $value => $weight) {
            if (($pick -= $weight) <= 0) {
                return $value;
            }
        }

        return array_key_first($weights);
    }
}
