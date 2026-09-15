<?php

namespace Gadya\Cms\Filament\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Ai\Agents\MetaWriter;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Ai\Prompter;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Illuminate\Support\Str;
use Throwable;

/**
 * How a page or article appears in search results and link previews,
 * with a preview of the result and a button that writes the snippet
 * from the page's own words.
 *
 * Shared between pages and articles: the fields are the same, only the
 * path they sit under in the form differs.
 */
class SeoSection
{
    /**
     * @param  string  $path  Where the fields live in the form state, e.g. "draft.seo." or "".
     */
    public static function make(string $path = '', string $urlPrefix = ''): Section
    {
        return Section::make('In search results')
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->description('Leave blank and the title and description of the page are used.')
            ->headerActions(array_values(array_filter([static::writeAction($path)])))
            ->schema([
                ViewField::make('serp_preview')
                    ->hiddenLabel()
                    ->dehydrated(false)
                    ->view('gadya-cms::filament.forms.serp-preview')
                    ->viewData(['path' => $path, 'urlPrefix' => $urlPrefix]),
                TextInput::make($path.'meta_title')
                    ->label('Title in search results')
                    ->maxLength(70)
                    ->live(onBlur: true)
                    ->helperText(fn (?string $state): string => strlen((string) $state).' of 30–60 characters'),
                Textarea::make($path.'meta_description')
                    ->label('Description in search results')
                    ->rows(3)
                    ->maxLength(320)
                    ->live(onBlur: true)
                    ->helperText(fn (?string $state): string => strlen((string) $state).' of 140–160 characters'),
                MediaSelect::make($path.'og_image', 'Photo when shared'),
                TextInput::make($path.'canonical')
                    ->label('Canonical address')
                    ->url()
                    ->maxLength(255)
                    ->helperText('Only if this page copies another one; search engines credit that one instead.'),
                Toggle::make($path.'noindex')
                    ->label('Keep out of search engines')
                    ->helperText('Visitors can still open it from a link.'),
            ])
            ->collapsible()
            ->collapsed();
    }

    /**
     * Writes the snippet from whatever the form holds - unsaved edits
     * included - so the flow is: write the page, press the button, save.
     */
    public static function writeAction(string $path): ?Action
    {
        if (! GadyaCmsPlugin::get()->hasAi()) {
            return null;
        }

        return Action::make('writeSeo')
            ->label('Write with AI')
            ->icon(Heroicon::OutlinedSparkles)
            ->visible(fn (): bool => app(AiSettings::class)->isConfigured())
            ->action(function (Set $set, $livewire) use ($path): void {
                $content = static::contentFromState($livewire->form->getRawState());

                if (mb_strlen($content) < 40) {
                    Notification::make()->warning()->title('Not enough written yet')->body('The snippet is written from the page, so write the page first.')->send();

                    return;
                }

                try {
                    $meta = app(Prompter::class)->prompt(app(MetaWriter::class), "Write the search snippet for this page:\n\n{$content}");
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Could not write it')->body(mb_substr($exception->getMessage(), 0, 300))->send();

                    return;
                }

                $set($path.'meta_title', (string) ($meta['meta_title'] ?? ''));
                $set($path.'meta_description', (string) ($meta['meta_description'] ?? ''));

                Notification::make()->success()->title('Snippet written')->body('Check the two fields, then save.')->send();
            });
    }

    /**
     * Flatten the form's live state into readable text for the model,
     * dropping ids, flags and the snippet fields themselves so the output
     * never feeds on itself.
     *
     * @param  array<string, mixed>  $state
     */
    public static function contentFromState(array $state): string
    {
        $skip = ['meta_title', 'meta_description', 'og_image', 'canonical', 'noindex', 'slug', 'ai_meta', 'image', 'hero_image', 'type', 'status', 'publish_at', 'unpublish_at'];
        $lines = [];

        $walk = function (array $values) use (&$walk, &$lines, $skip): void {
            foreach ($values as $key => $value) {
                if (in_array($key, $skip, true)) {
                    continue;
                }

                if (is_array($value)) {
                    $walk($value);

                    continue;
                }

                if (! is_string($value) || mb_strlen(trim($value)) < 3) {
                    continue;
                }

                $text = trim(strip_tags($value));

                if ($text !== '' && ! Str::isUuid($text) && ! str_ends_with($text, '.webp')) {
                    $lines[] = is_string($key) ? "{$key}: {$text}" : $text;
                }
            }
        };

        $walk($state);

        return Str::limit(implode("\n", $lines), 8000, '');
    }
}
