<?php

namespace Gadya\Cms\Upgrade\Steps;

use Gadya\Cms\Upgrade\UpgradeSteps;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * `php artisan storage:link` on a site whose photo library is on a local
 * disk that `filesystems.links` publishes (public/storage, normally) and
 * whose link is missing - without it, every photo on the site answers 404.
 */
class LinkPublicStorage
{
    public function key(): string
    {
        return 'cms.storage-link';
    }

    public function description(): string
    {
        return 'Link public/storage so the photo library is served';
    }

    public function phase(): string
    {
        return UpgradeSteps::SERVER;
    }

    public function shouldRun(): bool
    {
        $link = $this->link();

        return $link !== null && ! file_exists($link) && ! is_link($link);
    }

    /** The link `storage:link` makes to the photo library's folder, if it makes one. */
    private function link(): ?string
    {
        $disk = (string) config('gadya-cms.media.disk', 'public');

        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            return null;
        }

        $root = rtrim((string) config("filesystems.disks.{$disk}.root"), '/');
        $links = (array) config('filesystems.links', [public_path('storage') => storage_path('app/public')]);

        foreach ($links as $link => $target) {
            if (rtrim((string) $target, '/') === $root) {
                return (string) $link;
            }
        }

        return null;
    }

    public function run(): string
    {
        $exitCode = Artisan::call('storage:link', ['--no-interaction' => true]);
        $output = trim(Artisan::output());

        if ($exitCode !== 0) {
            throw new RuntimeException($output ?: 'storage:link failed.');
        }

        return $output ?: 'Linked public/storage.';
    }
}
