<?php

namespace Gadya\Cms\Brand;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * The CSS `filter` that turns a black image into a given colour, so a logo
 * served as one PNG can wear any site's ink. A port of the well-known SPSA
 * solver (Barrett Sonntag's "CSS filter generator"), seeded per colour so
 * the same colour always gives the same filter, and cached for good.
 */
class ColorFilter
{
    private const PREFIX = 'brightness(0) saturate(100%) ';

    /** @var array{0: float, 1: float, 2: float} */
    private array $target;

    /** @var array{0: float, 1: float, 2: float} */
    private array $targetHsl;

    public static function for(string $hex): string
    {
        $hex = static::normalise($hex);

        return Cache::rememberForever('gadya-cms.color-filter.'.$hex, fn (): string => (new self($hex))->solve()['filter']);
    }

    public static function normalise(string $hex): string
    {
        $hex = ltrim(strtolower(trim($hex)), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (preg_match('/^[0-9a-f]{6}$/', $hex) !== 1) {
            throw new InvalidArgumentException("[{$hex}] is not a hex colour.");
        }

        return '#'.$hex;
    }

    public function __construct(string $hex)
    {
        $hex = ltrim(static::normalise($hex), '#');

        $this->target = [(float) hexdec(substr($hex, 0, 2)), (float) hexdec(substr($hex, 2, 2)), (float) hexdec(substr($hex, 4, 2))];
        $this->targetHsl = $this->hsl($this->target);

        mt_srand(crc32($hex));
    }

    /**
     * @return array{filter: string, loss: float}
     */
    public function solve(): array
    {
        $wide = $this->solveWide();
        $narrow = $this->solveNarrow($wide);
        $best = $narrow['loss'] < $wide['loss'] ? $narrow : $wide;

        return ['filter' => self::PREFIX.$this->css($best['values']), 'loss' => $best['loss']];
    }

    /**
     * What the filter turns black into, for checking a solution.
     *
     * @param  list<float>  $values
     * @return array{0: float, 1: float, 2: float}
     */
    public function apply(array $values): array
    {
        $color = [0.0, 0.0, 0.0];
        $color = $this->invert($color, $values[0] / 100);
        $color = $this->sepia($color, $values[1] / 100);
        $color = $this->saturate($color, $values[2] / 100);
        $color = $this->hueRotate($color, $values[3] * 3.6);
        $color = $this->linear($color, $values[4] / 100);

        return $this->linear($color, $values[5] / 100, -(0.5 * $values[5] / 100) + 0.5);
    }

    /**
     * @return array{values: list<float>, loss: float}
     */
    private function solveWide(): array
    {
        $best = ['values' => [], 'loss' => INF];

        for ($i = 0; $best['loss'] > 25 && $i < 3; $i++) {
            $result = $this->spsa(5, [60, 180, 18000, 600, 1.2, 1.2], 15, [50, 20, 3750, 50, 100, 100], 1000);

            if ($result['loss'] < $best['loss']) {
                $best = $result;
            }
        }

        return $best;
    }

    /**
     * @param  array{values: list<float>, loss: float}  $wide
     * @return array{values: list<float>, loss: float}
     */
    private function solveNarrow(array $wide): array
    {
        $big = $wide['loss'] + 1;

        return $this->spsa($wide['loss'], [0.25 * $big, 0.25 * $big, $big, 0.25 * $big, 0.2 * $big, 0.2 * $big], 2, $wide['values'], 500);
    }

    /**
     * Simultaneous perturbation stochastic approximation.
     *
     * @param  list<float>  $a
     * @param  list<float>  $values
     * @return array{values: list<float>, loss: float}
     */
    private function spsa(float $A, array $a, float $c, array $values, int $iterations): array
    {
        $best = $values;
        $bestLoss = INF;

        for ($k = 0; $k < $iterations; $k++) {
            $ck = $c / (($k + 1) ** (1 / 6));
            $deltas = [];
            $high = [];
            $low = [];

            foreach ($values as $i => $value) {
                $deltas[$i] = mt_rand() / mt_getrandmax() > 0.5 ? 1 : -1;
                $high[$i] = $value + $ck * $deltas[$i];
                $low[$i] = $value - $ck * $deltas[$i];
            }

            $difference = $this->loss($high) - $this->loss($low);

            foreach ($values as $i => $value) {
                $gradient = $difference / (2 * $ck) * $deltas[$i];
                $values[$i] = $this->fix($value - $a[$i] / ($A + $k + 1) * $gradient, $i);
            }

            $loss = $this->loss($values);

            if ($loss < $bestLoss) {
                $best = $values;
                $bestLoss = $loss;
            }
        }

        return ['values' => $best, 'loss' => $bestLoss];
    }

    private function fix(float $value, int $index): float
    {
        $max = match ($index) {
            2 => 7500.0,
            4, 5 => 200.0,
            default => 100.0,
        };

        if ($index === 3) {
            if ($value > $max) {
                return fmod($value, $max);
            }

            return $value < 0 ? $max + fmod($value, $max) : $value;
        }

        return max(0.0, min($max, $value));
    }

    /**
     * @param  list<float>  $values
     */
    private function loss(array $values): float
    {
        $color = $this->apply($values);
        $hsl = $this->hsl($color);

        return abs($color[0] - $this->target[0]) + abs($color[1] - $this->target[1]) + abs($color[2] - $this->target[2])
            + abs($hsl[0] - $this->targetHsl[0]) + abs($hsl[1] - $this->targetHsl[1]) + abs($hsl[2] - $this->targetHsl[2]);
    }

    /**
     * @param  list<float>  $values
     */
    private function css(array $values): string
    {
        $round = fn (int $index, float $multiplier = 1): int => (int) round($values[$index] * $multiplier);

        return "invert({$round(0)}%) sepia({$round(1)}%) saturate({$round(2)}%) hue-rotate({$round(3, 3.6)}deg) brightness({$round(4)}%) contrast({$round(5)}%)";
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $c
     * @param  list<float>  $m
     * @return array{0: float, 1: float, 2: float}
     */
    private function multiply(array $c, array $m): array
    {
        return [
            $this->clamp($c[0] * $m[0] + $c[1] * $m[1] + $c[2] * $m[2]),
            $this->clamp($c[0] * $m[3] + $c[1] * $m[4] + $c[2] * $m[5]),
            $this->clamp($c[0] * $m[6] + $c[1] * $m[7] + $c[2] * $m[8]),
        ];
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $c
     * @return array{0: float, 1: float, 2: float}
     */
    private function hueRotate(array $c, float $degrees): array
    {
        $angle = $degrees / 180 * M_PI;
        $sin = sin($angle);
        $cos = cos($angle);

        return $this->multiply($c, [
            0.213 + $cos * 0.787 - $sin * 0.213, 0.715 - $cos * 0.715 - $sin * 0.715, 0.072 - $cos * 0.072 + $sin * 0.928,
            0.213 - $cos * 0.213 + $sin * 0.143, 0.715 + $cos * 0.285 + $sin * 0.140, 0.072 - $cos * 0.072 - $sin * 0.283,
            0.213 - $cos * 0.213 - $sin * 0.787, 0.715 - $cos * 0.715 + $sin * 0.715, 0.072 + $cos * 0.928 + $sin * 0.072,
        ]);
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $c
     * @return array{0: float, 1: float, 2: float}
     */
    private function sepia(array $c, float $value): array
    {
        $rest = 1 - $value;

        return $this->multiply($c, [
            0.393 + 0.607 * $rest, 0.769 - 0.769 * $rest, 0.189 - 0.189 * $rest,
            0.349 - 0.349 * $rest, 0.686 + 0.314 * $rest, 0.168 - 0.168 * $rest,
            0.272 - 0.272 * $rest, 0.534 - 0.534 * $rest, 0.131 + 0.869 * $rest,
        ]);
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $c
     * @return array{0: float, 1: float, 2: float}
     */
    private function saturate(array $c, float $value): array
    {
        return $this->multiply($c, [
            0.213 + 0.787 * $value, 0.715 - 0.715 * $value, 0.072 - 0.072 * $value,
            0.213 - 0.213 * $value, 0.715 + 0.285 * $value, 0.072 - 0.072 * $value,
            0.213 - 0.213 * $value, 0.715 - 0.715 * $value, 0.072 + 0.928 * $value,
        ]);
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $c
     * @return array{0: float, 1: float, 2: float}
     */
    private function linear(array $c, float $slope, float $intercept = 0): array
    {
        return [
            $this->clamp($c[0] * $slope + $intercept * 255),
            $this->clamp($c[1] * $slope + $intercept * 255),
            $this->clamp($c[2] * $slope + $intercept * 255),
        ];
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $c
     * @return array{0: float, 1: float, 2: float}
     */
    private function invert(array $c, float $value): array
    {
        return [
            $this->clamp(($value + $c[0] / 255 * (1 - 2 * $value)) * 255),
            $this->clamp(($value + $c[1] / 255 * (1 - 2 * $value)) * 255),
            $this->clamp(($value + $c[2] / 255 * (1 - 2 * $value)) * 255),
        ];
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $c
     * @return array{0: float, 1: float, 2: float}
     */
    private function hsl(array $c): array
    {
        [$r, $g, $b] = [$c[0] / 255, $c[1] / 255, $c[2] / 255];
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l * 100];
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => ($g - $b) / $d + ($g < $b ? 6 : 0),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        } / 6;

        return [$h * 100, $s * 100, $l * 100];
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(255.0, $value));
    }
}
