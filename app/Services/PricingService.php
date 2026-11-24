<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Pixelforge pricing.
 *
 * The user pays the raw Pixelforge cost plus a 25% markup. The up-front
 * price is an estimate because output tokens are only known on completion;
 * on completion the ACTUAL price is recomputed from the API-reported token
 * counts. Failed generations cost $0 (enforced where a generation is
 * marked failed, not here).
 *
 * This class is deliberately free of framework dependencies (beyond the
 * size validation) so it can be unit tested in isolation.
 */
class PricingService
{
    /** Markup applied on top of the raw Pixelforge cost. */
    public const MARKUP = 1.25;

    /** Output tokens assumed when estimating before submission. */
    public const ESTIMATED_OUTPUT_TOKENS = 900;

    /** Flat input tokens charged per reference image. */
    public const REFERENCE_IMAGE_TOKENS = 1600;

    /** USD rates per 1,000 tokens, keyed by image size. */
    private const RATES = [
        '1024x1024' => ['input' => 0.040, 'output' => 0.160],
        '1024x1536' => ['input' => 0.050, 'output' => 0.200],
        '1536x1024' => ['input' => 0.050, 'output' => 0.200],
    ];

    public static function sizes(): array
    {
        return array_keys(self::RATES);
    }

    /** Prompt tokens: one token per 4 characters, rounded up. */
    public function promptTokens(string $prompt): int
    {
        return (int) ceil(mb_strlen($prompt) / 4);
    }

    /** Total input tokens: prompt tokens + 1,600 per reference image. */
    public function inputTokens(string $prompt, int $referenceCount = 0): int
    {
        return $this->promptTokens($prompt) + (self::REFERENCE_IMAGE_TOKENS * $referenceCount);
    }

    /** Raw Pixelforge cost (before markup) for the given token counts. */
    public function cost(int $inputTokens, int $outputTokens, string $size): float
    {
        $rates = $this->rates($size);

        return ($inputTokens / 1000 * $rates['input'])
            + ($outputTokens / 1000 * $rates['output']);
    }

    /** What the user pays for a given raw cost (cost + 25% markup). */
    public function userPrice(float $cost): float
    {
        return round($cost * self::MARKUP, 4);
    }

    /**
     * Up-front estimate: output tokens are unknown, so assume 900.
     *
     * @return array{input_tokens:int, output_tokens:int, cost:float, price:float}
     */
    public function estimate(string $prompt, string $size, int $referenceCount = 0): array
    {
        $inputTokens = $this->inputTokens($prompt, $referenceCount);
        $cost = $this->cost($inputTokens, self::ESTIMATED_OUTPUT_TOKENS, $size);

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => self::ESTIMATED_OUTPUT_TOKENS,
            'cost' => round($cost, 6),
            'price' => $this->userPrice($cost),
        ];
    }

    /**
     * Actual price from the token counts reported by the API.
     *
     * @return array{input_tokens:int, output_tokens:int, cost:float, price:float}
     */
    public function actual(int $inputTokens, int $outputTokens, string $size): array
    {
        $cost = $this->cost($inputTokens, $outputTokens, $size);

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost' => round($cost, 6),
            'price' => $this->userPrice($cost),
        ];
    }

    private function rates(string $size): array
    {
        if (! isset(self::RATES[$size])) {
            throw new InvalidArgumentException("Unsupported image size: {$size}");
        }

        return self::RATES[$size];
    }
}
