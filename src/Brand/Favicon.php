<?php

namespace Gadya\Cms\Brand;

use Gadya\Cms\Content\PanelBrand;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Support\Images;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * The little square in the browser tab, made from the site's own logo.
 *
 * Almost every small business site we take over arrives without one, and
 * it is nobody's job to notice: the client does not know the word, and it
 * is too small a thing to raise. So the site makes its own - the logo,
 * trimmed and centred on the brand's background - and a client who has a
 * proper one keeps it, because a real favicon in `public/` is served by
 * the web server before Laravel is asked.
 */
class Favicon
{
    /** The sizes browsers actually ask for. */
    public const SIZES = [32, 180, 512];

    /** How long a generated icon is kept before it is drawn again. */
    private const CACHE_DAYS = 30;

    public function __construct(
        private readonly PanelBrand $brand,
        private readonly Images $images,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('gadya-cms.brand.favicon', true);
    }

    /**
     * Whether the site already has a favicon of its own in `public/`, in
     * which case we do not offer one at all.
     */
    public function siteHasItsOwn(): bool
    {
        foreach (['favicon.ico', 'favicon.png'] as $name) {
            if (File::exists(public_path($name))) {
                return true;
            }
        }

        return false;
    }

    /** A PNG at this size, drawn from the logo or from the site's initials. */
    public function png(int $size): string
    {
        $size = in_array($size, self::SIZES, true) ? $size : 32;

        return Cache::remember(
            'gadya-cms.favicon.'.$this->signature().'.'.$size,
            now()->addDays(self::CACHE_DAYS),
            fn (): string => $this->draw($size),
        );
    }

    /**
     * A real .ico, which is a 22-byte header wrapped around a PNG. Some
     * browsers and a great many link previewers still ask for it by name.
     */
    public function ico(): string
    {
        $png = $this->png(32);

        return pack('vvv', 0, 1, 1)
            .pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen($png), 22)
            .$png;
    }

    /**
     * What the `<head>` should say. Empty when the site has its own, so
     * the tags never point at two different icons.
     *
     * @return list<array{rel: string, href: string, sizes: string|null, type: string}>
     */
    public function tags(): array
    {
        if (! $this->enabled() || $this->siteHasItsOwn()) {
            return [];
        }

        return [
            ['rel' => 'icon', 'href' => url('/favicon.ico'), 'sizes' => 'any', 'type' => 'image/x-icon'],
            ['rel' => 'icon', 'href' => url('/favicon-32.png'), 'sizes' => '32x32', 'type' => 'image/png'],
            ['rel' => 'apple-touch-icon', 'href' => url('/apple-touch-icon.png'), 'sizes' => '180x180', 'type' => 'image/png'],
        ];
    }

    /**
     * The web app manifest, so a client who adds the site to her phone's
     * home screen gets her own icon rather than a screenshot.
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return [
            'name' => $this->name(),
            'short_name' => Str::limit($this->name(), 12, ''),
            'icons' => [
                ['src' => url('/favicon-32.png'), 'sizes' => '32x32', 'type' => 'image/png'],
                ['src' => url('/favicon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
            ],
            'theme_color' => $this->colour(),
            'background_color' => $this->colour(),
            'display' => 'standalone',
            'start_url' => '/',
        ];
    }

    /**
     * Drawn from the logo where there is one, and from the business's
     * initials where there is not - which is still better than the blank
     * page browsers show instead.
     */
    private function draw(int $size): string
    {
        $canvas = rescue(fn (): ?ImageInterface => $this->fromLogo($size), null, report: false)
            ?? $this->fromInitials($size);

        return $this->images->png($canvas);
    }

    private function fromLogo(int $size): ?ImageInterface
    {
        $binary = $this->logoBinary();

        if ($binary === null) {
            return null;
        }

        $logo = $this->images->read($binary);

        /*
         * Contained rather than cropped: a wordmark cropped to a square
         * becomes one illegible letter. The padding keeps it off the
         * edges, where a browser rounds the corners.
         */
        $inner = max(1, (int) round($size * 0.82));
        $logo->scaleDown(width: $inner, height: $inner);

        $canvas = $this->images->read($this->square($size));

        /* `place` in one release, `insert` in another; both centre it. */
        $put = method_exists($canvas, 'place') ? 'place' : 'insert';

        return $canvas->{$put}($logo, 'center');
    }

    /**
     * The business's initials, white on the brand colour.
     *
     * Drawn with GD's own bitmap font and scaled up, because a font file
     * is the one thing we cannot count on being installed: an icon whose
     * letters are a little soft is worth having, and a flat coloured
     * square with nothing on it is not.
     */
    private function fromInitials(int $size): ImageInterface
    {
        return $this->images->read($this->initialsPng($size));
    }

    private function initialsPng(int $size): string
    {
        $initials = $this->initials();

        /*
         * Drawn small and scaled up: GD's built-in font has fixed sizes,
         * so the letters are laid out at the size they come in and the
         * whole square is then enlarged to the size asked for.
         */
        $small = 32;
        $canvas = imagecreatetruecolor($small, $small);
        [$r, $g, $b] = $this->rgb($this->colour());
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));

        $white = imagecolorallocate($canvas, 255, 255, 255);
        $font = 5;
        $width = imagefontwidth($font) * strlen($initials);
        $height = imagefontheight($font);

        imagestring($canvas, $font, (int) (($small - $width) / 2), (int) (($small - $height) / 2), $initials, $white);

        if ($size !== $small) {
            $scaled = imagescale($canvas, $size, $size, IMG_NEAREST_NEIGHBOUR);

            if ($scaled !== false) {
                $canvas = $scaled;
            }
        }

        ob_start();
        imagepng($canvas);

        return (string) ob_get_clean();
    }

    /** A blank square, transparent unless a colour is given. */
    private function square(int $size, ?string $colour = null): string
    {
        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        if ($colour === null) {
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        } else {
            [$r, $g, $b] = $this->rgb($colour);
            imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** The logo's bytes, from the media library or the site's own files. */
    private function logoBinary(): ?string
    {
        $logo = config('gadya-cms.brand.favicon_source') ?: $this->brand->logo();

        if (! is_string($logo) || $logo === '') {
            return null;
        }

        $item = rescue(fn (): ?Media => Media::query()->where('filename', $logo)->first(), null, report: false);

        if ($item !== null && ! $item->is_legacy) {
            return rescue(fn (): ?string => Storage::disk($item->disk)->get($item->path), null, report: false);
        }

        foreach ([public_path(config('gadya-cms.media.legacy_directory', 'images/site').'/'.$logo), public_path($logo)] as $path) {
            if (File::exists($path)) {
                return File::get($path);
            }
        }

        return null;
    }

    /** Changes whenever the logo or the colour does, so the icon is redrawn. */
    private function signature(): string
    {
        return substr(sha1((string) $this->brand->logo().'|'.$this->colour().'|'.$this->name()), 0, 12);
    }

    private function name(): string
    {
        return (string) (config('gadya-cms.seo.site_name') ?: config('gadya-cms.brand.name') ?: config('app.name'));
    }

    private function colour(): string
    {
        $tokens = rescue(fn (): array => $this->brand->tokens(), [], report: false);

        $colour = $tokens['primary'] ?? config('gadya-cms.brand.colors.primary') ?? '#1f2937';

        return is_string($colour) && preg_match('/^#[0-9a-f]{6}$/i', $colour) ? $colour : '#1f2937';
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private function initials(): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $this->name(), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '?';
        }

        return Str::upper(count($words) === 1
            ? Str::substr($words[0], 0, 2)
            : Str::substr($words[0], 0, 1).Str::substr($words[1], 0, 1));
    }

    /**
     * Whether what was drawn is one flat colour - which is what a failed
     * draw looks like, and is worse than no icon at all because nobody
     * would ever notice it was wrong.
     */
    public function looksBlank(string $png): bool
    {
        $image = @imagecreatefromstring($png);

        if ($image === false) {
            return true;
        }

        $first = null;

        for ($x = 0; $x < imagesx($image); $x += 2) {
            for ($y = 0; $y < imagesy($image); $y += 2) {
                $colour = imagecolorat($image, $x, $y);
                $first ??= $colour;

                if ($colour !== $first) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Throws away what was drawn, so the next request draws it again. */
    public function forget(): void
    {
        foreach (self::SIZES as $size) {
            rescue(fn () => Cache::forget('gadya-cms.favicon.'.$this->signature().'.'.$size), null, report: false);
        }
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        try {
            $bytes = strlen($this->png(32));
            $drawn = true;
        } catch (Throwable) {
            $bytes = 0;
            $drawn = false;
        }

        return [
            'enabled' => $this->enabled(),
            'site_has_its_own' => $this->siteHasItsOwn(),
            'from' => $this->logoBinary() === null ? 'initials' : 'logo',
            'drawn' => $drawn,
            'bytes' => $bytes,
            /* A single flat colour means nothing legible was drawn. */
            'blank' => $drawn ? $this->looksBlank($this->png(32)) : true,
        ];
    }
}
