<?php

namespace Workbench\App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use Notifiable;

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password', 'role'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'invited_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function canManageContent(): bool
    {
        return in_array($this->role, ['contributor', 'editor', 'admin'], true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->canManageContent();
    }
}
