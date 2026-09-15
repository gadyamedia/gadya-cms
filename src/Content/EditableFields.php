<?php

namespace Gadya\Cms\Content;

/**
 * The allow-list of document paths the live editor may write, and the kind
 * of editor each one gets. A path that is not matched here can never be
 * written by an inline edit, whatever the browser sends.
 */
class EditableFields
{
    public function typeFor(string $path): ?string
    {
        foreach ($this->patterns() as $pattern => $type) {
            if ($this->matches($pattern, $path)) {
                return $type;
            }
        }

        return null;
    }

    public function allows(string $path): bool
    {
        return $this->typeFor($path) !== null;
    }

    /**
     * @return array<string, string>
     */
    public function patterns(): array
    {
        /** @var array<string, string> $patterns */
        $patterns = config('gadya-cms.editable_fields', []);

        return $patterns;
    }

    private function matches(string $pattern, string $path): bool
    {
        $patternSegments = explode('.', $pattern);
        $pathSegments = explode('.', $path);

        if (count($patternSegments) !== count($pathSegments)) {
            return false;
        }

        foreach ($patternSegments as $index => $segment) {
            if ($segment === '*') {
                continue;
            }

            if ($segment !== $pathSegments[$index]) {
                return false;
            }
        }

        return true;
    }
}
