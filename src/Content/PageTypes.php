<?php

namespace Gadya\Cms\Content;

use Illuminate\Support\Str;

/**
 * What each kind of page is called and which fields it carries.
 *
 * A site's page types are not all the same shape: a location has an
 * address and opening hours, a legal page has neither. Rather than one
 * list of fields for every page, each type may name its own; a type that
 * says nothing gets the site's default fields.
 */
class PageTypes
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $types = [];

        foreach ((array) config('gadya-cms.pages.types', []) as $type => $definition) {
            if (is_string($type) && is_array($definition)) {
                $types[$type] = $definition;
            }
        }

        return $types;
    }

    public function label(string $type): string
    {
        return (string) ($this->all()[$type]['label'] ?? Str::headline($type));
    }

    /**
     * The fields at the top of this type's edit screen.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fieldsFor(?string $type): array
    {
        $fields = $this->all()[$type]['fields'] ?? config('gadya-cms.pages.content_fields', []);

        return is_array($fields) ? $fields : [];
    }

    /**
     * Section kinds this type may use, when it narrows them.
     *
     * @return list<string>
     */
    public function sectionTypesFor(?string $type): array
    {
        $types = $this->all()[$type]['section_types'] ?? config('gadya-cms.pages.section_types', []);

        return array_values(array_filter((array) $types, 'is_string'));
    }

    /**
     * Types the client may create: those listed as creatable, plus the
     * application's own `creatable_types` for a site that never declared
     * any types at all.
     *
     * @return list<string>
     */
    public function creatable(): array
    {
        $creatable = [];

        foreach ($this->all() as $type => $definition) {
            if (($definition['creatable'] ?? false) === true) {
                $creatable[] = $type;
            }
        }

        return $creatable !== []
            ? $creatable
            : array_values(array_filter((array) config('gadya-cms.pages.creatable_types', ['content']), 'is_string'));
    }
}
