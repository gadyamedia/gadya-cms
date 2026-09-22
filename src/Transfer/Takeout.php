<?php

namespace Gadya\Cms\Transfer;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Models\Post;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Everything the client owns, in a form she can use without us: the
 * photographs as files, the articles and pages as Markdown, the enquiries
 * as a spreadsheet, and the whole site as JSON for whoever comes next.
 *
 * We offer this because a client who cannot leave has to be kept rather
 * than earned. It is also the honest answer to "what happens to my
 * website if you go under".
 */
class Takeout
{
    public function __construct(
        private readonly SiteExporter $exporter,
        private readonly SiteContentRepository $repository,
    ) {}

    /**
     * Write the takeout to a zip and return its path.
     */
    public function write(string $path): string
    {
        if (! str_ends_with(strtolower($path), '.zip')) {
            throw new RuntimeException('A takeout is a .zip; it carries the photographs.');
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip extension is not installed, so the photographs cannot be packed.');
        }

        File::ensureDirectoryExists(dirname($path));

        /* The machine-readable export does the heavy lifting, photos and all. */
        $this->exporter->write($path, withMedia: true);

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Could not write {$path}.");
        }

        $zip->addFromString('README.md', $this->readme());

        foreach ($this->pages() as $name => $markdown) {
            $zip->addFromString("pages/{$name}.md", $markdown);
        }

        foreach ($this->articles() as $name => $markdown) {
            $zip->addFromString("articles/{$name}.md", $markdown);
        }

        if (($enquiries = $this->enquiries()) !== null) {
            $zip->addFromString('enquiries.csv', $enquiries);
        }

        $zip->close();

        return $path;
    }

    /**
     * Every page's words as Markdown, keyed by file name.
     *
     * @return array<string, string>
     */
    private function pages(): array
    {
        $files = [];

        foreach ((array) ($this->repository->published()['pages'] ?? []) as $slug => $page) {
            if (! is_array($page)) {
                continue;
            }

            $lines = ['# '.($page['title'] ?? $slug), ''];

            array_walk_recursive($page, function ($value, $key) use (&$lines): void {
                if (is_string($value) && strlen($value) > 2 && ! in_array($key, ['type', 'status', 'image', 'hero_image'], true)) {
                    $lines[] = $value;
                    $lines[] = '';
                }
            });

            $files[Str::slug((string) $slug) ?: 'page'] = implode("\n", $lines);
        }

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function articles(): array
    {
        return Post::query()
            ->get()
            ->mapWithKeys(fn (Post $post): array => [
                Str::slug((string) $post->slug) ?: 'article-'.$post->id => implode("\n", [
                    '# '.$post->title,
                    '',
                    $post->published_at === null ? 'Not published.' : 'Published '.$post->published_at->format('j F Y').'.',
                    '',
                    (string) $post->excerpt,
                    '',
                    (string) $post->content,
                ]),
            ])
            ->all();
    }

    /** Every enquiry ever sent through the site, as a spreadsheet. */
    private function enquiries(): ?string
    {
        $submissions = FormSubmission::query()->orderBy('created_at')->get();

        if ($submissions->isEmpty()) {
            return null;
        }

        $columns = $submissions
            ->flatMap(fn (FormSubmission $submission): array => array_keys((array) $submission->data))
            ->unique()
            ->values()
            ->all();

        $rows = [array_merge(['sent_at', 'form', 'page'], $columns)];

        foreach ($submissions as $submission) {
            $rows[] = array_merge(
                [$submission->created_at?->toDateTimeString(), (string) $submission->form, (string) $submission->path],
                array_map(fn (string $column): string => $this->flat(data_get($submission->data, $column)), $columns),
            );
        }

        $csv = '';

        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($cell): string => '"'.str_replace('"', '""', (string) $cell).'"', $row))."\n";
        }

        return $csv;
    }

    private function flat(mixed $value): string
    {
        return is_array($value) ? implode('; ', array_map(fn ($item): string => is_scalar($item) ? (string) $item : '', $value)) : (string) $value;
    }

    private function readme(): string
    {
        $name = (string) (config('gadya-cms.seo.site_name') ?: config('app.name'));

        return <<<MARKDOWN
        # {$name} - everything on this website

        This is yours. It was made on {$this->today()} and nothing in it needs Gadya Media,
        our software, or our permission to read.

        ## What is in here

        - `site.json` - every page, article, setting and redirect, in the shape another
          developer can import. This is the whole website as data.
        - `pages/` - the words on each page, as plain Markdown files you can open in any
          text editor.
        - `articles/` - the same for each article.
        - `site-media/` and the other image folders - every photograph at full size, plus
          the smaller versions the site serves.
        - `enquiries.csv` - every enquiry anyone has ever sent through the site, as a
          spreadsheet. Open it in Excel or Numbers.

        ## If you are moving to someone else

        Hand them `site.json` and the image folders. Any competent Laravel developer can
        read it in an afternoon; the format is documented in the Gadya CMS repository under
        `docs/transfer.md`. You do not need to ask us for anything, and we will not make it
        difficult.

        ## If you are keeping the site with us

        Then this is simply a copy, and worth keeping somewhere that is not the website -
        the same as you would with anything you would hate to lose.
        MARKDOWN;
    }

    private function today(): string
    {
        return now()->format('j F Y');
    }
}
