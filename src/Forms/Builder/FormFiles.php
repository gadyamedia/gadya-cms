<?php

namespace Gadya\Cms\Forms\Builder;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Where a form's uploads and signatures are kept, and the only way to
 * open one again.
 *
 * Never somewhere the web server hands out: the photo library's disk is
 * used when it is a cloud disk (with private visibility), and the
 * application's private `local` disk when the library is on a public
 * folder. Every file has a random name, and the admin opens it through a
 * signed link that works for half an hour, for someone allowed to read
 * enquiries.
 */
class FormFiles
{
    public function diskName(): string
    {
        $configured = config('gadya-cms.forms.builder.uploads.disk');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $media = (string) config('gadya-cms.media.disk', 'public');

        return config("filesystems.disks.{$media}.driver") === 'local' ? 'local' : $media;
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * @return array{path: string, name: string, size: int, mime: string}
     */
    public function store(UploadedFile $file, string $slug): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $path = $this->directory($slug).'/'.Str::random(40).'.'.$extension;

        $this->disk()->put($path, (string) file_get_contents($file->getRealPath()), ['visibility' => 'private']);

        return [
            'path' => $path,
            'name' => Str::limit($file->getClientOriginalName() ?: 'upload.'.$extension, 180, ''),
            'size' => (int) $file->getSize(),
            'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
        ];
    }

    /**
     * A drawn signature, from the PNG the browser sends.
     *
     * @return array{path: string, name: string, size: int, mime: string}|null
     */
    public function storeSignature(string $dataUrl, string $slug): ?array
    {
        if (! preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', $dataUrl, $match)) {
            return null;
        }

        $png = base64_decode($match[1], true);

        if ($png === false || ! str_starts_with($png, "\x89PNG")) {
            return null;
        }

        $path = $this->directory($slug).'/'.Str::random(40).'.png';

        $this->disk()->put($path, $png, ['visibility' => 'private']);

        return ['path' => $path, 'name' => 'signature.png', 'size' => strlen($png), 'mime' => 'image/png'];
    }

    /** A link to one file of one enquiry, good for half an hour. */
    public function signedUrl(int $submissionId, string $field, int $index): string
    {
        return URL::temporarySignedRoute('gadya-cms.forms.file', now()->addMinutes(30), [
            'submission' => $submissionId,
            'field' => $field,
            'index' => $index,
        ]);
    }

    public function delete(string $path): void
    {
        rescue(fn () => $this->disk()->delete($path), report: false);
    }

    private function directory(string $slug): string
    {
        return trim((string) config('gadya-cms.forms.builder.uploads.directory', 'form-uploads'), '/').'/'.$slug.'/'.now()->format('Y-m');
    }
}
