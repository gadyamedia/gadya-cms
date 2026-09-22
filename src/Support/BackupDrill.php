<?php

namespace Gadya\Cms\Support;

use Gadya\Cms\Options\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Opens the newest backup and checks that what is inside it could
 * actually be restored.
 *
 * Everyone says "backups included". Almost nobody looks inside one until
 * the day they need it, which is the worst possible day to find out the
 * archive is eleven bytes of nothing. This opens the newest archive,
 * finds the database dump, and satisfies itself that the dump contains
 * real tables - then writes down what it found, pass or fail, so there is
 * something to show a client besides a promise.
 */
class BackupDrill
{
    /** A dump smaller than this is not a database. */
    private const MIN_DUMP_BYTES = 2048;

    /** Where the last drill's result is kept. */
    public const OPTION = 'backups.last_drill';

    public function __construct(
        private readonly Backups $backups,
        private readonly Options $options,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function last(): ?array
    {
        $last = $this->options->get(self::OPTION);

        return is_array($last) ? $last : null;
    }

    /**
     * Run the drill and record it.
     *
     * @return array{passed: bool, ran_at: string, archive: string|null, archive_mb: float|null, dump_mb: float|null, tables: int|null, says: string}
     */
    public function run(): array
    {
        $result = $this->attempt();

        $this->options->set(self::OPTION, $result);

        return $result;
    }

    /**
     * @return array{passed: bool, ran_at: string, archive: string|null, archive_mb: float|null, dump_mb: float|null, tables: int|null, says: string}
     */
    private function attempt(): array
    {
        $failed = fn (string $says): array => [
            'passed' => false,
            'ran_at' => now()->toIso8601String(),
            'archive' => null,
            'archive_mb' => null,
            'dump_mb' => null,
            'tables' => null,
            'says' => $says,
        ];

        if (! $this->backups->configured()) {
            return $failed('This site takes no backups of its own, so there was nothing to test.');
        }

        if (! class_exists(ZipArchive::class)) {
            return $failed('The zip extension is not installed, so the archive could not be opened.');
        }

        $newest = $this->newestArchive();

        if ($newest === null) {
            return $failed('No backup archive was found on any of the configured disks.');
        }

        [$disk, $path] = $newest;

        try {
            /*
             * Copied to a local file first: an archive on S3 cannot be
             * opened in place, and a stream would be downloaded anyway.
             */
            $local = tempnam(sys_get_temp_dir(), 'gadya-drill-').'.zip';
            file_put_contents($local, Storage::disk($disk)->get($path));

            $zip = new ZipArchive;

            if ($zip->open($local) !== true) {
                @unlink($local);

                return $failed('The newest archive could not be opened. A backup that will not open is not a backup.');
            }

            $dump = $this->findDump($zip);

            if ($dump === null) {
                $zip->close();
                @unlink($local);

                return $failed('The newest archive opened but holds no database dump, so only files could be restored from it.');
            }

            $contents = (string) $zip->getFromIndex($dump);
            $tables = preg_match_all('/CREATE TABLE/i', $contents);
            $archiveBytes = filesize($local) ?: 0;
            $zip->close();
            @unlink($local);

            if (strlen($contents) < self::MIN_DUMP_BYTES || $tables === 0) {
                return $failed('The database dump inside the newest archive is empty. The backup is running but saving nothing.');
            }

            return [
                'passed' => true,
                'ran_at' => now()->toIso8601String(),
                'archive' => $path,
                'archive_mb' => round($archiveBytes / 1048576, 1),
                'dump_mb' => round(strlen($contents) / 1048576, 1),
                'tables' => $tables,
                'says' => 'The newest backup opened and its database dump holds '.$tables.' tables, so it could be restored.',
            ];
        } catch (Throwable $exception) {
            return $failed('The newest archive could not be read: '.mb_substr($exception->getMessage(), 0, 150));
        }
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function newestArchive(): ?array
    {
        $name = (string) config('backup.backup.name', config('app.name'));
        $best = null;

        foreach ((array) config('backup.backup.destination.disks', []) as $diskName) {
            rescue(function () use ($diskName, $name, &$best): void {
                foreach (Storage::disk((string) $diskName)->files($name) as $file) {
                    if (! str_ends_with(strtolower($file), '.zip')) {
                        continue;
                    }

                    $at = Storage::disk((string) $diskName)->lastModified($file);

                    if ($best === null || $at > $best['at']) {
                        $best = ['at' => $at, 'disk' => (string) $diskName, 'path' => $file];
                    }
                }
            }, null, report: false);
        }

        return $best === null ? null : [$best['disk'], $best['path']];
    }

    private function findDump(ZipArchive $zip): ?int
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = strtolower((string) $zip->getNameIndex($index));

            if (str_contains($name, 'db-dumps/') || str_ends_with($name, '.sql')) {
                return $index;
            }
        }

        return null;
    }

    /** How long ago the last drill ran, in hours. */
    public function hoursSinceLastDrill(): ?int
    {
        $last = $this->last();

        return isset($last['ran_at']) ? (int) Carbon::parse((string) $last['ran_at'])->diffInHours() : null;
    }
}
