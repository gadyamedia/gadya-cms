<?php

namespace Gadya\Cms\Forms\Builder;

use Gadya\Cms\Forms\Attribution;
use Gadya\Cms\Forms\MergeTags;
use Gadya\Cms\Models\FormSubmission;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One form's enquiries as a spreadsheet: a column per question, headed
 * with the question's own words, then the notes and dates, then where
 * each enquiry came from.
 *
 * Excel files come from OpenSpout, which Filament already brings with it
 * for its own exports - no dependency of the CMS's own. Where it is not
 * installed, only CSV is offered.
 */
class FormExport
{
    public static function canWriteExcel(): bool
    {
        return class_exists(Writer::class) && class_exists(Row::class);
    }

    /**
     * @param  Collection<int, FormSubmission>  $submissions
     */
    public function download(Collection $submissions, string $name, string $format = 'csv'): StreamedResponse
    {
        $rows = $this->rows($submissions);
        $filename = Str::slug($name ?: 'enquiries').'-'.now()->format('Y-m-d');

        if ($format === 'xlsx' && self::canWriteExcel()) {
            return response()->streamDownload(function () use ($rows): void {
                $writer = new Writer;
                $writer->openToFile('php://output');

                foreach ($rows as $row) {
                    $writer->addRow(Row::fromValues($row));
                }

                $writer->close();
            }, $filename.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  Collection<int, FormSubmission>  $submissions
     * @return list<list<string>>
     */
    public function rows(Collection $submissions): array
    {
        $labels = [];

        foreach ($submissions as $submission) {
            $labels = [...$labels, ...$submission->fieldLabels()];
        }

        $attribution = self::attributionColumns($submissions);

        $rows = [['Received', 'Status', 'Answered', 'Follow up', 'Notes', 'Page', 'Country', ...array_values($labels), ...array_values($attribution)]];

        foreach ($submissions as $submission) {
            $rows[] = [
                $submission->created_at?->toDateTimeString() ?? '',
                (string) $submission->status,
                $submission->answered_at?->toDateTimeString() ?? '',
                $submission->follow_up_at?->toDateString() ?? '',
                (string) $submission->notes,
                (string) $submission->path,
                (string) $submission->country,
                ...array_map(fn (string $key): string => $this->cell(($submission->data ?? [])[$key] ?? ''), array_keys($labels)),
                ...array_map(fn (string $key): string => $this->cell(self::attributionOf($submission)[$key] ?? ''), array_keys($attribution)),
            ];
        }

        return $rows;
    }

    /**
     * The parts of where enquiries came from that any of them has, as
     * columns after the answers: key => heading.
     *
     * @param  iterable<FormSubmission>  $submissions
     * @return array<string, string>
     */
    public static function attributionColumns(iterable $submissions): array
    {
        $present = [];

        foreach ($submissions as $submission) {
            $present = [...$present, ...array_filter(self::attributionOf($submission), fn ($value): bool => is_string($value) && $value !== '')];
        }

        return array_intersect_key(Attribution::labels(), $present);
    }

    /**
     * @return array<string, mixed>
     */
    public static function attributionOf(FormSubmission $submission): array
    {
        return (array) (($submission->meta ?? [])['attribution'] ?? []);
    }

    /** A spreadsheet never runs what a visitor typed as a formula. */
    private function cell(mixed $value): string
    {
        return self::safe(MergeTags::text($value));
    }

    /**
     * Text a spreadsheet will show rather than run - what a visitor typed,
     * or put in an address (`?utm_source==HYPERLINK(...)`).
     */
    public static function safe(string $text): string
    {
        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }
}
