<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Content\PublicDocument;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Quality\AccessibilityRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

/**
 * The site's accessibility statement, written from the record of what was
 * actually checked and fixed rather than from a promise.
 */
class AccessibilityStatementController extends Controller
{
    public function __construct(
        private readonly AccessibilityRecord $record,
        private readonly SiteContentRepository $repository,
        private readonly PublicDocument $publicDocument,
        private readonly EditContext $editContext,
    ) {}

    public function __invoke(): View
    {
        $this->editContext->boot();

        return view('gadya-cms::accessibility.statement', [
            'statement' => $this->record->statement(),
            'checked' => $this->record->exists(),
            'site' => $this->publicDocument->from($this->repository->forRequest()),
            'page' => [
                'title' => 'Accessibility statement',
                'heading' => 'Accessibility statement',
                'description' => 'What we check, what we have fixed, and what is still outstanding.',
            ],
        ]);
    }
}
