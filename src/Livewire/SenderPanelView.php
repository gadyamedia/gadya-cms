<?php

namespace Gadya\Cms\Livewire;

use Gadya\Cms\Mail\SenderPanel;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The "Who is this sent by?" panel, with a Refresh beside what was sent
 * lately.
 *
 * The portal's answer is cached for a minute, so redrawing the screen
 * never waits on the portal. It is asked afresh only when a person opens
 * the panel (the component is mounted once, not on every keystroke in the
 * form around it), when they press Refresh, and after a test send.
 */
class SenderPanelView extends Component
{
    public const SENT = 'gadya-cms-mail-sent';

    public int $limit = 3;

    /** Only for the request that asked: never kept between requests. */
    protected bool $fresh = false;

    public function mount(int $limit = 3): void
    {
        $this->limit = $limit;
        $this->fresh = true;
    }

    #[On(self::SENT)]
    public function refresh(): void
    {
        $this->fresh = true;
    }

    public function render(): View
    {
        return view('gadya-cms::livewire.sender-panel', [
            'panel' => app(SenderPanel::class)->describe(fresh: $this->fresh),
            'limit' => $this->limit,
            'refreshable' => true,
        ]);
    }
}
