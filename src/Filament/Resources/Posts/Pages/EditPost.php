<?php

namespace Gadya\Cms\Filament\Resources\Posts\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Blog\ArticleRequest;
use Gadya\Cms\Blog\ContentAudit;
use Gadya\Cms\Blog\GenerateArticle;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Resources\Posts\PostResource;
use Gadya\Cms\Models\Post;
use Throwable;

class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_values(array_filter([
            Action::make('view')
                ->label('View')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn (Post $record): string => url($record->publicPath()))
                ->openUrlInNewTab()
                ->visible(fn (Post $record): bool => $record->isLive() && (bool) config('gadya-cms.blog.routes', true)),
            GadyaCmsPlugin::get()->hasAi() ? $this->rewriteAction() : null,
            DeleteAction::make(),
        ]));
    }

    /**
     * Rewrites the whole article from a topic, keeping the address so any
     * link to it survives. The client is warned first: it replaces every
     * word she has.
     */
    protected function rewriteAction(): Action
    {
        return Action::make('rewrite')
            ->label('Rewrite with AI')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('info')
            ->visible(fn (): bool => app(AiSettings::class)->isConfigured())
            ->schema([
                Textarea::make('topic')
                    ->label('What should it be about?')
                    ->default(fn (Post $record): string => $record->title)
                    ->required()
                    ->rows(3),
            ])
            ->modalHeading('Rewrite this article with AI')
            ->modalDescription('The title, text, excerpt, search snippet and questions are all replaced, using the aim set in the rail. The address stays the same. Review before publishing.')
            ->action(function (array $data, Post $record, GenerateArticle $generate, ContentAudit $audit): void {
                try {
                    $fields = $generate->handle(new ArticleRequest(
                        topic: $data['topic'],
                        keyword: (string) $record->target_keyword,
                        location: (string) $record->target_location,
                        intent: (string) ($record->search_intent ?: 'informational'),
                    ), $record->getKey());
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Could not write it')->body(mb_substr($exception->getMessage(), 0, 300))->send();

                    return;
                }

                unset($fields['slug']);

                $record->update([
                    ...$fields,
                    'source' => Post::SOURCE_AI,
                    'ai_meta' => [...($record->ai_meta ?? []), ...$fields['ai_meta']],
                ]);

                $audit->record($record);

                /*
                 * A full re-fill, not refreshFormData: the FAQ lives in a
                 * repeater, which refreshFormData cannot hydrate, and the
                 * next save would wipe the generated questions back out.
                 */
                $this->fillForm();

                Notification::make()->success()->title('Article rewritten')->body('Read it through, then publish when you are happy.')->send();
            });
    }

    protected function afterSave(): void
    {
        app(ContentAudit::class)->record($this->getRecord()->fresh());
    }
}
