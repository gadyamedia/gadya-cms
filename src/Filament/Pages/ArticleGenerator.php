<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Blog\ArticleRequest;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Jobs\GenerateArticleDraft;
use Gadya\Cms\Models\ArticleGeneration;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

/**
 * Ask for an article and come back to a draft.
 *
 * @property-read Schema $form
 */
class ArticleGenerator extends Page
{
    protected string $view = 'gadya-cms::filament.pages.article-generator';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getContentNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'Write with AI';

    protected static ?string $title = 'Write with AI';

    protected static ?int $navigationSort = 5;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['search_intent' => 'informational']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('topic')
                    ->label('What should the article be about?')
                    ->required()
                    ->maxLength(500)
                    ->rows(2)
                    ->placeholder('E.g. "How to plan a birthday party for a five-year-old without losing your mind"'),
                TextInput::make('target_keyword')
                    ->label('Search phrase to rank for')
                    ->maxLength(120)
                    ->placeholder('kids birthday party ideas'),
                TextInput::make('target_location')
                    ->label('Place to mention')
                    ->maxLength(120)
                    ->placeholder('Staten Island'),
                Select::make('search_intent')
                    ->label('What the reader wants')
                    ->options(array_combine(ArticleRequest::intents(), array_map(Str::headline(...), ArticleRequest::intents())))
                    ->default('informational')
                    ->required()
                    ->native(false),
            ])
            ->columns(2)
            ->statePath('data');
    }

    public function generate(AiSettings $settings, SiteContext $siteContext): void
    {
        if (! $settings->isConfigured()) {
            Notification::make()->warning()->title('AI is not set up yet')->body('Choose a provider and add a key under Settings → AI first.')->send();

            return;
        }

        if (ArticleGeneration::query()->active()->exists()) {
            Notification::make()->warning()->title('An article is already being written')->body('Wait for it to finish before asking for another.')->send();

            return;
        }

        $data = $this->form->getState();

        $generation = ArticleGeneration::query()->create([
            'site_id' => $siteContext->id(),
            'status' => ArticleGeneration::STATUS_QUEUED,
            'stage' => 'Waiting for a worker',
            'topic' => $data['topic'],
            'target_keyword' => $data['target_keyword'] ?? null,
            'target_location' => $data['target_location'] ?? null,
            'search_intent' => $data['search_intent'],
            'requested_by' => auth()->id(),
        ]);

        try {
            GenerateArticleDraft::dispatch($generation)->afterCommit();
        } catch (Throwable $exception) {
            $generation->update([
                'status' => ArticleGeneration::STATUS_FAILED,
                'stage' => 'Could not reach the queue',
                'failure_reason' => mb_substr($exception->getMessage(), 0, 900),
            ]);

            Notification::make()->danger()->title('Could not start')->body($exception->getMessage())->send();

            return;
        }

        $this->form->fill(['search_intent' => 'informational']);

        Notification::make()->success()->title('Writing started')->body('The draft appears below when it is ready. You can leave this page.')->send();
    }

    public function getActiveGenerationProperty(): ?ArticleGeneration
    {
        return ArticleGeneration::query()->active()->latest('id')->first();
    }

    /**
     * @return Collection<int, ArticleGeneration>
     */
    public function getRecentGenerationsProperty(): Collection
    {
        return ArticleGeneration::query()->with('post')->latest('id')->limit(6)->get();
    }

    public function getIsConfiguredProperty(): bool
    {
        return app(AiSettings::class)->isConfigured();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can((string) config('gadya-cms.gate', 'manage-content')) ?? false;
    }
}
