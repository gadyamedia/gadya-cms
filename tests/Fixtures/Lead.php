<?php

namespace Gadya\Cms\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A site's own record of an enquiry, as the client site that prompted
 * form destinations keeps one: a status, the brand, the campaign and the
 * consent it was given with.
 */
class Lead extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'email', 'phone', 'topic', 'message', 'status', 'source', 'landing_page', 'brand', 'locale', 'consented_at', 'privacy_version'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['consented_at' => 'datetime'];
    }
}
