<?php

namespace Gadya\Cms\Access;

use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What each role may do inside the CMS.
 *
 * Roles stay the application's own strings; this only reads which
 * abilities the configuration grants each one. A role configured as a
 * plain label keeps the 0.2 behaviour - everything but the team - so an
 * existing install changes nothing by upgrading. The administrator role
 * always has everything.
 */
class Abilities
{
    public const CONTENT = 'content';

    public const ARTICLES = 'articles';

    public const PHOTOS = 'photos';

    public const ENQUIRIES = 'enquiries';

    public const PUBLISH = 'publish';

    public const SETTINGS = 'settings';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::CONTENT, self::ARTICLES, self::PHOTOS, self::ENQUIRIES, self::PUBLISH, self::SETTINGS];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::CONTENT => 'Edit pages, the menu and the look',
            self::ARTICLES => 'Write and edit articles',
            self::PHOTOS => 'Upload and manage photos',
            self::ENQUIRIES => 'Read enquiries',
            self::PUBLISH => 'Publish changes to the live site',
            self::SETTINGS => 'Change redirects and AI settings',
        ];
    }

    /**
     * @return list<string>
     */
    public function forRole(?string $role): array
    {
        if ($role === null) {
            return [];
        }

        if ($role === (string) config('gadya-cms.users.admin_role', 'admin')) {
            return static::all();
        }

        $roles = (array) config('gadya-cms.users.roles', []);

        if (! array_key_exists($role, $roles)) {
            return [];
        }

        $definition = $roles[$role];

        if (is_string($definition)) {
            return array_values(array_diff(static::all(), [self::SETTINGS]));
        }

        $abilities = (array) ($definition['abilities'] ?? []);

        return in_array('*', $abilities, true)
            ? static::all()
            : array_values(array_intersect(static::all(), $abilities));
    }

    public function allows(?Authenticatable $user, string $ability): bool
    {
        if ($user === null) {
            return false;
        }

        return in_array($ability, $this->forRole($this->roleOf($user)), true);
    }

    public function roleOf(Authenticatable $user): ?string
    {
        $role = $user->role ?? null;

        return $role instanceof BackedEnum ? (string) $role->value : ($role === null ? null : (string) $role);
    }

    /**
     * @return array<string, string>
     */
    public static function roleLabels(): array
    {
        $labels = [];

        foreach ((array) config('gadya-cms.users.roles', []) as $role => $definition) {
            $labels[(string) $role] = is_string($definition) ? $definition : (string) ($definition['label'] ?? $role);
        }

        return $labels;
    }

    public static function gate(string $ability): string
    {
        return 'gadya-cms.'.$ability;
    }
}
