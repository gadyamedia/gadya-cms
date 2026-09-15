<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An old address that should send visitors somewhere else: a page that
 * moved, a link in a printed flyer that was never quite right, a URL from
 * the site before this one.
 */
class Redirect extends Model
{
    protected $table = 'gadyacms_redirects';

    /** @var list<string> */
    protected $fillable = ['site_id', 'from_path', 'to_path', 'status_code', 'hits', 'last_hit_at'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status_code' => 301,
        'hits' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    /**
     * Paths are stored one way - leading slash, no trailing slash, lower
     * case - so a visitor typing any spelling of the old address matches.
     */
    public static function normalise(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?? $path;
        $path = '/'.trim($path, '/');

        return mb_strtolower($path);
    }

    protected static function booted(): void
    {
        static::saving(function (self $redirect): void {
            $redirect->from_path = static::normalise($redirect->from_path);

            if (! preg_match('~^https?://~i', $redirect->to_path)) {
                $redirect->to_path = '/'.ltrim($redirect->to_path, '/');
            }
        });
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
