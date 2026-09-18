<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Content\PublicDocument;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Events\EventCalendar;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class EventController extends Controller
{
    public function __construct(
        private readonly EventCalendar $calendar,
        private readonly SiteContentRepository $repository,
        private readonly PublicDocument $public,
        private readonly EditContext $editor,
    ) {}

    public function index(): View
    {
        return view('gadya-cms::events.index', [
            'upcoming' => $this->calendar->upcoming(),
            'past' => $this->calendar->past((int) config('gadya-cms.events.past', 6)),
            'site' => $this->site(),
            'page' => [
                'title' => (string) config('gadya-cms.events.title', 'What’s on'),
                'heading' => (string) (config('gadya-cms.events.heading') ?: config('gadya-cms.events.title', 'What’s on')),
                'description' => (string) config('gadya-cms.events.description', ''),
            ],
        ]);
    }

    public function show(string $slug): View
    {
        $this->editor->boot();

        $event = $this->editor->showsDraft()
            ? $this->calendar->find($slug)
            : $this->calendar->findLive($slug);

        abort_if($event === null, 404);

        return view('gadya-cms::events.show', [
            'event' => $event,
            'structured' => $this->calendar->structuredData($event),
            'site' => $this->site(),
            'page' => [
                'title' => $event->title,
                'heading' => $event->title,
                'description' => (string) $event->summary,
                'hero_image' => $event->image,
                'seo' => ['noindex' => ! $event->isLive()],
            ],
        ]);
    }

    public function calendar(): Response
    {
        return response($this->calendar->ics())
            ->header('Content-Type', 'text/calendar; charset=utf-8')
            ->header('Content-Disposition', 'inline; filename="'.trim((string) config('gadya-cms.events.prefix', 'events'), '/').'.ics"')
            ->header('Cache-Control', 'public, max-age=900');
    }

    /**
     * @return array<string, mixed>
     */
    private function site(): array
    {
        $this->editor->boot();

        return $this->public->from($this->repository->forRequest());
    }
}
