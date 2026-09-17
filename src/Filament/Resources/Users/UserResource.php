<?php

namespace Gadya\Cms\Filament\Resources\Users;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Filament\Resources\Users\Pages\ListUsers;
use Gadya\Cms\Services\InvitePanelUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use UnitEnum;

/**
 * The people who may work on the site.
 *
 * Roles are the application's own strings rather than anything this package
 * defines, so a site can call its roles whatever it likes and the panel only
 * needs the labels to show.
 */
class UserResource extends Resource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Team';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function getModel(): string
    {
        return (string) config('auth.providers.users.model');
    }

    public static function getModelLabel(): string
    {
        return 'team member';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            Select::make('role')
                ->options(fn (): array => static::roleOptions())
                ->required()
                ->live()
                ->helperText(fn (?Model $record, ?string $state): ?string => static::isLastAdministrator($record)
                    ? 'This is the only administrator, so their role cannot be changed.'
                    : static::describeRole($state))
                ->disabled(fn (?Model $record): bool => static::isLastAdministrator($record)),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable()->copyable(),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => static::roleOptions()[static::roleValue($state)] ?? (string) static::roleValue($state))
                    ->color(fn ($state): string => static::roleValue($state) === static::adminRole() ? 'primary' : 'gray'),
                TextColumn::make('status')
                    ->state(fn (Model $record): string => static::statusFor($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Invited' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('last_login_at')
                    ->label('Last signed in')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')->options(fn (): array => static::roleOptions()),
            ])
            ->headerActions([static::inviteAction()])
            ->recordActions([
                static::editAction(),
                static::resendAction(),
                static::removeAction(),
            ])
            ->defaultSort('name');
    }

    /**
     * Saved with forceFill rather than update: an application is right to
     * keep `role` out of its fillable list, so a signup form can never
     * grant anyone anything. That also means an ordinary save drops it
     * silently, which would leave a role change looking as though it had
     * worked.
     */
    protected static function editAction(): EditAction
    {
        return EditAction::make()
            ->using(function (Model $record, array $data): Model {
                if (static::isLastAdministrator($record)) {
                    $data['role'] = static::adminRole();
                }

                $record->forceFill($data)->save();

                return $record;
            });
    }

    protected static function inviteAction(): Action
    {
        return Action::make('invite')
            ->label('Invite someone')
            ->icon(Heroicon::OutlinedEnvelope)
            ->modalDescription('They will get an email with a link to choose their own password. No password is ever sent.')
            ->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->rule(Rule::unique((new (static::getModel()))->getTable(), 'email')),
                Select::make('role')
                    ->options(fn (): array => static::roleOptions())
                    ->default((string) config('gadya-cms.users.default_role', 'editor'))
                    ->live()
                    ->helperText(fn (?string $state): ?string => static::describeRole($state))
                    ->required(),
            ])
            ->action(function (array $data, InvitePanelUser $invite): void {
                $invite->invite($data['name'], $data['email'], $data['role'], auth()->user());

                Notification::make()
                    ->success()
                    ->title('Invitation sent')
                    ->body("{$data['email']} can now set a password and sign in.")
                    ->send();
            });
    }

    protected static function resendAction(): Action
    {
        return Action::make('resend')
            ->label('Resend invitation')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (Model $record): bool => $record->last_login_at === null)
            ->requiresConfirmation()
            ->modalDescription('This sends a fresh link and stops the previous one working.')
            ->action(function (Model $record, InvitePanelUser $invite): void {
                $invite->sendInvitation($record, auth()->user());

                Notification::make()->success()->title('Invitation sent again')->send();
            });
    }

    /**
     * Taking someone off the team, with the two guards that stop a site
     * locking everybody out: you cannot remove yourself, and the last
     * administrator cannot be removed at all.
     */
    protected static function removeAction(): Action
    {
        return Action::make('remove')
            ->label('Remove')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Remove from the team')
            ->modalDescription('They lose access immediately. Anything they published stays.')
            ->hidden(fn (Model $record): bool => static::isCurrentUser($record) || static::isLastAdministrator($record))
            ->action(function (Model $record): void {
                $record->delete();

                Notification::make()->success()->title('Removed from the team')->send();
            });
    }

    public static function isCurrentUser(?Model $record): bool
    {
        return $record !== null && auth()->id() === $record->getKey();
    }

    /**
     * Whether removing or demoting this person would leave the site with no
     * administrator at all.
     */
    public static function isLastAdministrator(?Model $record): bool
    {
        if ($record === null || static::roleValue($record->role) !== static::adminRole()) {
            return false;
        }

        return static::getModel()::query()
            ->where('role', static::adminRole())
            ->whereKeyNot($record->getKey())
            ->doesntExist();
    }

    public static function statusFor(Model $record): string
    {
        return match (true) {
            $record->last_login_at !== null => 'Active',
            $record->invited_at !== null => 'Invited',
            default => 'Never signed in',
        };
    }

    /**
     * What a role may do, in the client's words, so choosing one is not a
     * guess.
     */
    public static function describeRole(?string $role): ?string
    {
        $abilities = app(Abilities::class)->forRole($role);

        if ($abilities === []) {
            return null;
        }

        return 'Can: '.implode('; ', array_map(fn (string $ability): string => lcfirst(Abilities::labels()[$ability] ?? $ability), $abilities)).'.';
    }

    protected static function adminRole(): string
    {
        return (string) config('gadya-cms.users.admin_role', 'admin');
    }

    /** Roles may be an enum on the application's model, or a plain string. */
    protected static function roleValue(mixed $role): ?string
    {
        return $role instanceof BackedEnum ? (string) $role->value : ($role === null ? null : (string) $role);
    }

    /**
     * @return array<string, string>
     */
    protected static function roleOptions(): array
    {
        return Abilities::roleLabels();
    }

    /**
     * Only people who may work on the site appear here. A site's customers
     * are not team members and have no business in this list.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('role', array_keys(static::roleOptions()));
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can((string) config('gadya-cms.users.gate', 'manage-users')) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/')];
    }
}
