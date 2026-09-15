<?php

namespace Gadya\Cms\Blog;

/**
 * What the client asked for, as the generator needs it.
 */
final class ArticleRequest
{
    public function __construct(
        public readonly string $topic,
        public readonly string $keyword = '',
        public readonly string $location = '',
        public readonly string $intent = 'informational',
    ) {}

    /**
     * @return list<string>
     */
    public static function intents(): array
    {
        return ['informational', 'commercial', 'local'];
    }
}
