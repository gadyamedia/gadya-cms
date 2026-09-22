<?php

namespace Gadya\Cms\Quality;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\BrokenLink;
use Gadya\Cms\Models\Event;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Models\Post;
use Illuminate\Support\Collection;

/**
 * What has quietly gone out of date.
 *
 * Small business websites do not fail, they drift: an enquiry nobody
 * answered, a What's on page whose last event was in March, a link to a
 * page that was deleted. None of it shows up as an error, so nobody is
 * told. This looks for the handful of things that are worth an email.
 */
class Drift
{
    /** An enquiry unanswered for longer than this needs saying. */
    public const LEAD_HOURS = 24;

    /** A site with nothing new for this long reads as abandoned. */
    public const STALE_ARTICLE_DAYS = 120;

    public function __construct(private readonly SiteContentRepository $repository) {}

    /**
     * Everything worth telling her, most urgent first.
     *
     * @return Collection<int, array{key: string, urgency: string, says: string, does: string}>
     */
    public function findings(): Collection
    {
        return collect([
            $this->unansweredEnquiries(),
            $this->emptyWhatsOn(),
            $this->brokenLinks(),
            $this->undescribedPhotos(),
            $this->pagesWithoutDescriptions(),
            $this->quietBlog(),
            $this->missingBusinessDetails(),
        ])
            ->filter()
            ->sortBy(fn (array $finding): int => match ($finding['urgency']) {
                'now' => 0,
                'soon' => 1,
                default => 2,
            })
            ->values();
    }

    /** How many enquiries are sitting unanswered, and the oldest in hours. */
    public function unansweredLeads(): array
    {
        $unread = FormSubmission::query()->unread();

        $oldest = (clone $unread)->orderBy('created_at')->first();

        return [
            'count' => (clone $unread)->count(),
            'oldest_hours' => $oldest?->created_at === null ? 0 : (int) $oldest->created_at->diffInHours(),
        ];
    }

    /** @return array{key: string, urgency: string, says: string, does: string}|null */
    private function unansweredEnquiries(): ?array
    {
        $leads = $this->unansweredLeads();

        if ($leads['count'] === 0 || $leads['oldest_hours'] < self::LEAD_HOURS) {
            return null;
        }

        return [
            'key' => 'unanswered-enquiries',
            'urgency' => 'now',
            'says' => $leads['count'].' '.str('enquiry')->plural($leads['count']).' nobody has opened, the oldest '.$this->plainly($leads['oldest_hours']).' old.',
            'does' => 'Open the Enquiries screen and answer them. Somebody is waiting.',
        ];
    }

    /** @return array{key: string, urgency: string, says: string, does: string}|null */
    private function emptyWhatsOn(): ?array
    {
        if (! config('gadya-cms.events.routes', true)) {
            return null;
        }

        if (Event::query()->upcoming()->exists() || ! Event::query()->exists()) {
            return null;
        }

        $last = Event::query()->past()->first();

        return [
            'key' => 'empty-whats-on',
            'urgency' => 'soon',
            'says' => 'What\'s on has nothing coming up'.($last?->starts_at === null ? '' : '; the last one was '.$last->starts_at->format('j F')).'.',
            'does' => 'Add the next few dates, or ask us to take the page off the menu until there are some.',
        ];
    }

    /** @return array{key: string, urgency: string, says: string, does: string}|null */
    private function brokenLinks(): ?array
    {
        $count = BrokenLink::query()->unresolved()->where('kind', '!=', BrokenLink::VISITED)->count();

        if ($count === 0) {
            return null;
        }

        return [
            'key' => 'broken-links',
            'urgency' => 'soon',
            'says' => $count.' '.str('link')->plural($count).' on the site lead nowhere.',
            'does' => 'We fix these; they are listed under Broken links so you can see what we are doing.',
        ];
    }

    /** @return array{key: string, urgency: string, says: string, does: string}|null */
    private function undescribedPhotos(): ?array
    {
        $count = Media::query()
            ->where(fn ($query) => $query->whereNull('alt_text')->orWhere('alt_text', ''))
            ->count();

        if ($count === 0) {
            return null;
        }

        return [
            'key' => 'undescribed-photos',
            'urgency' => 'later',
            'says' => $count.' '.str('photo')->plural($count).' have no description, so a screen reader cannot say what they show.',
            'does' => 'Settings → Speed & accessibility will write them for you.',
        ];
    }

    /** @return array{key: string, urgency: string, says: string, does: string}|null */
    private function pagesWithoutDescriptions(): ?array
    {
        $missing = collect($this->repository->published()['pages'] ?? [])
            ->filter(fn ($page): bool => is_array($page) && blank($page['seo']['meta_description'] ?? null))
            ->count();

        if ($missing === 0) {
            return null;
        }

        return [
            'key' => 'pages-without-descriptions',
            'urgency' => 'later',
            'says' => $missing.' '.str('page')->plural($missing).' have nothing to show under their name in search results.',
            'does' => 'Settings → Speed & accessibility will write them for you.',
        ];
    }

    /** @return array{key: string, urgency: string, says: string, does: string}|null */
    private function quietBlog(): ?array
    {
        if (! config('gadya-cms.blog.routes', true) || ! Post::query()->live()->exists()) {
            return null;
        }

        $newest = Post::query()->live()->orderByDesc('published_at')->first();

        if ($newest?->published_at === null || $newest->published_at->greaterThan(now()->subDays(self::STALE_ARTICLE_DAYS))) {
            return null;
        }

        return [
            'key' => 'quiet-blog',
            'urgency' => 'later',
            'says' => 'The newest article is from '.$newest->published_at->format('F Y').'.',
            'does' => 'Google reads an untouched site as a closed business. Ask us for a few, or write one from Articles.',
        ];
    }

    /** @return array{key: string, urgency: string, says: string, does: string}|null */
    private function missingBusinessDetails(): ?array
    {
        $missing = collect(['telephone' => 'a telephone number', 'email' => 'an email address', 'address' => 'an address'])
            ->filter(fn (string $label, string $key): bool => blank(config("gadya-cms.seo.organization.{$key}")))
            ->values();

        if ($missing->isEmpty()) {
            return null;
        }

        return [
            'key' => 'missing-business-details',
            'urgency' => 'soon',
            'says' => 'Google is not being told '.$missing->join(', ', ' or ').' for the business.',
            'does' => 'Send them to us and we will add them, so the business shows properly in search and on maps.',
        ];
    }

    private function plainly(int $hours): string
    {
        return $hours < 48
            ? $hours.' '.str('hour')->plural($hours)
            : intdiv($hours, 24).' days';
    }
}
