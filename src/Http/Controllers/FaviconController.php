<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Brand\Favicon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Serves the icon the site drew for itself.
 */
class FaviconController extends Controller
{
    public function __construct(private readonly Favicon $favicon) {}

    public function ico(): Response
    {
        return $this->image($this->favicon->ico(), 'image/x-icon');
    }

    public function png(int $size): Response
    {
        abort_unless(in_array($size, Favicon::SIZES, true), 404);

        return $this->image($this->favicon->png($size), 'image/png');
    }

    public function apple(): Response
    {
        return $this->image($this->favicon->png(180), 'image/png');
    }

    public function manifest(): JsonResponse
    {
        return response()
            ->json($this->favicon->manifest(), 200, [], JSON_UNESCAPED_SLASHES)
            ->header('Content-Type', 'application/manifest+json; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    private function image(string $binary, string $type): Response
    {
        abort_unless($this->favicon->enabled(), 404);

        return response($binary)
            ->header('Content-Type', $type)
            /* A week: the icon only changes when the logo does. */
            ->header('Cache-Control', 'public, max-age=604800');
    }
}
