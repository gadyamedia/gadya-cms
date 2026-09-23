<?php

namespace Gadya\Cms\Brand;

use Closure;
use Gadya\Cms\Content\PanelBrand;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Support\Images;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use ImagickDraw;
use ImagickPixel;
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

    /** Bold fonts found on most Linux servers and Macs, for initials drawn by Imagick. */
    private const FONTS = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
    ];

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
     *
     * An empty file does not count. Laravel's skeleton ships a zero-byte
     * favicon.ico, so almost every site we build has one - and a browser
     * asking for it gets nothing, while the site looks as though it has an
     * icon. That file has to be deleted (the web server answers it before
     * Laravel is asked); `emptyPlaceholder()` says when it is there.
     */
    public function siteHasItsOwn(): bool
    {
        foreach (['favicon.ico', 'favicon.png', 'favicon.svg'] as $name) {
            $path = public_path($name);

            if (File::exists($path) && File::size($path) > 0) {
                return true;
            }
        }

        return false;
    }

    /** Where --write records that the files in public/ are ours to redraw. */
    private const WRITTEN_MARKER = 'favicon.gadya-cms';

    /**
     * Save the icon into public/ as real files, for a web server that
     * answers /favicon.ico from disk without asking Laravel.
     *
     * @return list<string> What was written, relative to public/.
     */
    public function writeFiles(): array
    {
        File::put(public_path('favicon.ico'), $this->ico());
        File::put(public_path('apple-touch-icon.png'), $this->png(180));
        File::put(public_path(self::WRITTEN_MARKER), $this->signature());

        return ['favicon.ico', 'apple-touch-icon.png'];
    }

    /** Whether the icon files in public/ were written by writeFiles(). */
    public function wroteOwnFiles(): bool
    {
        return File::exists(public_path(self::WRITTEN_MARKER));
    }

    /** Whether Laravel's empty favicon.ico is in public/, hiding the real one. */
    public function emptyPlaceholder(): bool
    {
        $path = public_path('favicon.ico');

        return File::exists($path) && File::size($path) === 0;
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
         * A logo with its own opaque background is scaled to fill the
         * tile and its own colour goes behind it, so there is no white
         * square floating inside a coloured one. A logo on transparency
         * is padded instead, on a colour chosen to contrast with it. Read
         * before trimming, which would take the tile away.
         */
        $own = $this->ownBackground($logo);

        /*
         * Logo files often carry empty space around the lettering, so the
         * square below would start in that space and catch only the edge
         * of the mark. Trimmed first, it starts at the mark itself.
         */
        if ($own === null) {
            rescue(fn () => $logo->trim(10), null, report: false);
        }

        /*
         * A lockup is usually a mark followed by the business's name. The
         * whole thing contained in a 32-pixel square is an illegible
         * smudge, so anything much wider than it is tall is cropped to its
         * leading square - which is the mark on a lockup, and the first
         * letters on a wordmark. Either reads.
         */
        if ($logo->width() > $logo->height() * 1.6) {
            $edge = $logo->height();
            $logo->crop($edge, $edge, 0, 0);
        }
        $inner = max(1, (int) round($size * ($own === null ? 0.84 : 1)));

        $logo->scaleDown(width: $inner, height: $inner);

        $canvas = $this->images->read($this->square($size, $own ?? $this->backdropFor($logo)));

        /*
         * `place` takes the position second; `insert` takes it fourth,
         * after the offsets - passing it second there is a type error,
         * which is how every logo quietly became a blank square.
         */
        return method_exists($canvas, 'place')
            ? $canvas->place($logo, 'center')
            : $canvas->insert($logo, 0, 0, 'center');
    }

    /**
     * The logo's own background colour, when it has one: the corners all
     * being the same opaque colour is what a logo on a solid tile looks
     * like, and is worth keeping.
     */
    private function ownBackground(ImageInterface $logo): ?string
    {
        return rescue(function () use ($logo): ?string {
            $pixels = $this->pixelsOf($this->images->png($logo));

            if ($pixels === null) {
                return null;
            }

            [$width, $height, $at] = $pixels;
            $corners = [];

            foreach ([[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1]] as [$x, $y]) {
                [$r, $g, $b, $alpha] = $at($x, $y);

                if ($alpha > 16) {
                    return null;
                }

                $corners[] = sprintf('#%02x%02x%02x', $r, $g, $b);
            }

            return count(array_unique($corners)) === 1 ? $corners[0] : null;
        }, null, report: false);
    }

    /**
     * What to put behind the logo: the brand colour, unless the logo is
     * itself dark, in which case white - a navy wordmark on a navy tile
     * is a navy tile.
     */
    private function backdropFor(ImageInterface $logo): string
    {
        $luminance = rescue(fn (): ?float => $this->luminanceOf($this->images->png($logo)), null, report: false);

        if ($luminance === null) {
            return $this->colour();
        }

        [$r, $g, $b] = $this->rgb($this->colour());
        $brand = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;

        /* Keep them apart; when they are not, white almost always works. */
        return abs($luminance - $brand) > 0.25 ? $this->colour() : ($luminance > 0.5 ? '#1f2937' : '#ffffff');
    }

    /** The average brightness of a PNG's opaque pixels, 0 to 1. */
    private function luminanceOf(string $png): ?float
    {
        $pixels = $this->pixelsOf($png);

        if ($pixels === null) {
            return null;
        }

        [$width, $height, $at] = $pixels;
        $total = 0.0;
        $counted = 0;

        for ($x = 0; $x < $width; $x += 2) {
            for ($y = 0; $y < $height; $y += 2) {
                [$r, $g, $b, $alpha] = $at($x, $y);

                /* Transparent pixels say nothing about the mark's colour. */
                if ($alpha > 64) {
                    continue;
                }

                $total += (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
                $counted++;
            }
        }

        return $counted === 0 ? null : $total / $counted;
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
        return function_exists('imagecreatetruecolor')
            ? $this->initialsWithGd($size)
            : $this->initialsWithImagick($size);
    }

    private function initialsWithGd(int $size): string
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

    /**
     * A server with Imagick and no GD. ImageMagick needs a font to draw
     * letters with, so a common system one is used when it is there and
     * ImageMagick's own default when it is not.
     */
    private function initialsWithImagick(int $size): string
    {
        $image = new Imagick;
        $image->newImage($size, $size, new ImagickPixel($this->colour()));

        $draw = new ImagickDraw;
        $draw->setFillColor(new ImagickPixel('#ffffff'));
        $draw->setFontSize($size * 0.45);
        $draw->setGravity(Imagick::GRAVITY_CENTER);
        $draw->setTextAntialias(true);

        foreach (self::FONTS as $font) {
            if (is_file($font)) {
                $draw->setFont($font);

                break;
            }
        }

        $image->annotateImage($draw, 0, 0, 0, $this->initials());
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    /** A blank square, transparent unless a colour is given. */
    private function square(int $size, ?string $colour = null): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            $image = new Imagick;
            $image->newImage($size, $size, new ImagickPixel($colour ?? 'transparent'));
            $image->setImageFormat('png');

            return $image->getImageBlob();
        }

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

    /**
     * A PNG's size and a way to read its pixels, through GD or Imagick -
     * whichever the server has. Each pixel is red, green and blue from 0
     * to 255 and transparency from 0 (opaque) to 127, as GD counts it.
     *
     * @return array{0: int, 1: int, 2: Closure(int, int): array{0: int, 1: int, 2: int, 3: int}}|null
     */
    private function pixelsOf(string $png): ?array
    {
        if (function_exists('imagecreatefromstring')) {
            $image = @imagecreatefromstring($png);

            if ($image === false) {
                return null;
            }

            imagepalettetotruecolor($image);

            return [imagesx($image), imagesy($image), function (int $x, int $y) use ($image): array {
                $colour = imagecolorat($image, $x, $y);

                return [($colour >> 16) & 0xFF, ($colour >> 8) & 0xFF, $colour & 0xFF, ($colour >> 24) & 0x7F];
            }];
        }

        if (! class_exists(Imagick::class)) {
            return null;
        }

        $image = new Imagick;
        $image->readImageBlob($png);

        return [$image->getImageWidth(), $image->getImageHeight(), function (int $x, int $y) use ($image): array {
            $colour = $image->getImagePixelColor($x, $y)->getColor(1);

            return [
                (int) round($colour['r'] * 255),
                (int) round($colour['g'] * 255),
                (int) round($colour['b'] * 255),
                (int) round((1 - ($colour['a'] ?? 1)) * 127),
            ];
        }];
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
        $pixels = $this->pixelsOf($png);

        if ($pixels === null) {
            return true;
        }

        [$width, $height, $at] = $pixels;
        $first = null;

        for ($x = 0; $x < $width; $x += 2) {
            for ($y = 0; $y < $height; $y += 2) {
                $colour = $at($x, $y);
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
        $error = null;

        try {
            $bytes = strlen($this->png(32));
            $drawn = true;
        } catch (Throwable $exception) {
            $bytes = 0;
            $drawn = false;
            $error = $exception->getMessage();
        }

        /* What was actually used, not what was available to try. */
        $fromLogo = rescue(fn (): bool => $this->fromLogo(32) !== null, false, report: false);

        return [
            'enabled' => $this->enabled(),
            'site_has_its_own' => $this->siteHasItsOwn(),
            'from' => $fromLogo ? 'logo' : 'initials',
            'drawn' => $drawn,
            'bytes' => $bytes,
            /* A single flat colour means nothing legible was drawn. */
            'blank' => $drawn ? $this->looksBlank($this->png(32)) : true,
            /* Why it was not drawn, so the command can say more than "check the driver". */
            'error' => $error,
        ];
    }
}
