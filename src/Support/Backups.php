<?php

namespace Gadya\Cms\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * What the site's own backups look like, when it takes any.
 *
 * Read-only and entirely optional: a site without `spatie/laravel-backup`
 * simply reports that it takes none, which is itself worth knowing in the
 * portal. Nothing here makes or deletes a backup.
 */
class Backups
{
    public function configured(): bool
    {
        return config('backup.backup.destination.disks') !== null;
    }

    /**
     * The newest archive on each configured disk.
     *
     * @return array{configured: bool, disks: list<array{disk: string, newest_at: string|null, size_mb: float|null, count: int, error: string|null}>}
     */
    public function state(): array
    {
        if (! $this->configured()) {
            return ['configured' => false, 'disks' => []];
        }

        $name = (string) config('backup.backup.name', config('app.name'));
        $disks = [];

        foreach ((array) config('backup.backup.destination.disks', []) as $diskName) {
            $disks[] = rescue(function () use ($diskName, $name): array {
                $files = collect(Storage::disk((string) $diskName)->files($name))
                    ->filter(fn (string $file): bool => str_ends_with(strtolower($file), '.zip'))
                    ->values();

                if ($files->isEmpty()) {
                    return ['disk' => (string) $diskName, 'newest_at' => null, 'size_mb' => null, 'count' => 0, 'error' => 'No archives on this disk.'];
                }

                $newest = $files->sortByDesc(fn (string $file): int => Storage::disk((string) $diskName)->lastModified($file))->first();

                return [
                    'disk' => (string) $diskName,
                    'newest_at' => Carbon::createFromTimestamp(Storage::disk((string) $diskName)->lastModified((string) $newest))->toIso8601String(),
                    'size_mb' => round(Storage::disk((string) $diskName)->size((string) $newest) / 1048576, 1),
                    'count' => $files->count(),
                    'error' => null,
                ];
            }, [
                'disk' => (string) $diskName,
                'newest_at' => null,
                'size_mb' => null,
                'count' => 0,
                'error' => 'Could not read this disk.',
            ], report: false);
        }

        return ['configured' => true, 'disks' => $disks];
    }

    /** How old the newest backup anywhere is, in hours. */
    public function newestAgeInHours(): ?int
    {
        $newest = collect($this->state()['disks'])
            ->pluck('newest_at')
            ->filter()
            ->map(fn (string $at): Carbon => Carbon::parse($at))
            ->max();

        return $newest === null ? null : (int) $newest->diffInHours();
    }
}
