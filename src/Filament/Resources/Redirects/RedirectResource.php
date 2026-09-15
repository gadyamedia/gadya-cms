<?php

namespace Gadya\Cms\Filament\Resources\Redirects;

use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Gadya\Cms\Filament\Resources\Redirects\Pages\ListRedirects;
use Gadya\Cms\Models\Redirect;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Old addresses and where they go now. Kept as a table the client can
 * edit rather than a file only a developer can, because the addresses
 * that need forwarding turn up in her inbox, not in a deploy.
 */
class RedirectResource extends Resource
{
    protected static ?string $model = Redirect::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnRight;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Redirects';

    protected static ?string $modelLabel = 'redirect';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('from_path')
                ->label('Old address')
                ->placeholder('/old-page')
                ->required()
                ->maxLength(255)
                ->rule('regex:~^/~')
                ->rule(fn (): Closure => static::notProtectedRule())
                ->unique(ignoreRecord: true)
                ->helperText('The part after the domain, starting with a slash.'),
            TextInput::make('to_path')
                ->label('Send visitors to')
                ->placeholder('/new-page or https://elsewhere.example')
                ->required()
                ->maxLength(500)
                ->rule('regex:~^(/|https?://)~')
                ->different('from_path'),
            Select::make('status_code')
                ->label('Kind')
                ->options([
                    301 => 'Permanent (search engines move to the new address)',
                    302 => 'Temporary (search engines keep the old address)',
                ])
                ->default(301)
                ->required()
                ->native(false),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('from_path')->label('Old address')->searchable()->sortable()->copyable(),
                TextColumn::make('to_path')->label('Goes to')->searchable()->wrap(),
                TextColumn::make('status_code')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state === 302 ? 'Temporary' : 'Permanent')
                    ->color(fn (int $state): string => $state === 302 ? 'warning' : 'success'),
                TextColumn::make('hits')->label('Used')->numeric()->sortable(),
                TextColumn::make('last_hit_at')->label('Last used')->since()->placeholder('Never')->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([DeleteBulkAction::make()])
            ->defaultSort('hits', 'desc');
    }

    /**
     * The panel and the editor must never be redirected away from, or the
     * client locks herself out with one typo.
     */
    protected static function notProtectedRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $path = Redirect::normalise((string) $value);

            foreach (['admin', (string) config('gadya-cms.editor.prefix', 'cms'), 'livewire'] as $prefix) {
                if ($path === '/'.$prefix || str_starts_with($path, '/'.$prefix.'/')) {
                    $fail("The address [{$value}] belongs to the site's own tools and cannot be redirected.");
                }
            }
        };
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('site_id', app(SiteContext::class)->id());
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can((string) config('gadya-cms.gate', 'manage-content')) ?? false;
    }

    public static function getPages(): array
    {
        return ['index' => ListRedirects::route('/')];
    }
}
