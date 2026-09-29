<?php

namespace Gadya\Cms\Forms\Builder;

/**
 * What a test of a form's notifications did, in plain words: what went
 * where, and - when something did not - the real reason and the fix.
 */
final class TestReport
{
    /** @var list<string> */
    public array $lines = [];

    /** @var list<string> */
    public array $problems = [];

    public function said(string $line): self
    {
        $this->lines[] = $line;

        return $this;
    }

    public function wentWrong(string $problem): self
    {
        $this->problems[] = $problem;

        return $this;
    }

    public function succeeded(): bool
    {
        return $this->problems === [] && $this->lines !== [];
    }

    /** Some went and some did not. */
    public function partly(): bool
    {
        return $this->problems !== [] && $this->lines !== [];
    }

    public function title(): string
    {
        return match (true) {
            $this->succeeded() => 'The test was sent',
            $this->partly() => 'Part of the test was sent',
            default => 'The test could not be sent',
        };
    }

    public function body(): string
    {
        return implode("\n", [...$this->lines, ...$this->problems]);
    }
}
