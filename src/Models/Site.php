<?php

namespace Gadya\Cms\Models;

use Gadya\Cms\Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    protected $table = 'gadyacms_sites';

    /** @var list<string> */
    protected $fillable = ['name', 'key', 'is_active'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Page, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    /** @return HasMany<Setting, $this> */
    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    /** @return HasMany<Media, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    /** @return HasMany<Revision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    protected static function newFactory(): Factory
    {
        return SiteFactory::new();
    }
}
