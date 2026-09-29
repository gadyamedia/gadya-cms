<?php

namespace Gadya\Cms\Forms\Builder;

use Gadya\Cms\Forms\MergeTags;
use Gadya\Cms\Models\FormSubmission;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One form's enquiries as a spreadsheet: a column per question, headed
 * with the question's own words, then the notes and dates.
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

        $rows = [['Received', 'Status', 'Answered', 'Follow up', 'Notes', 'Page', 'Country', ...array_values($labels)]];

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
            ];
        }

        return $rows;
    }

    /** A spreadsheet never runs what a visitor typed as a formula. */
    private function cell(mixed $value): string
    {
        $text = MergeTags::text($value);

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }
}
