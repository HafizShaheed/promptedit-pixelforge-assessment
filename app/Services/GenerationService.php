<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Shared state transitions for a Generation, used by both the polling job
 * and the webhook controller so completion/failure handling (pricing,
 * local image storage, $0 on failure) is identical on every path.
 */
class GenerationService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly PixelforgeClient $client,
    ) {}

    /**
     * Mark a generation completed from an API-shaped data array
     * (works for both GET /images/generations/{id} responses and
     * webhook payload "data" objects). The actual price is computed
     * from the API-reported token counts.
     */
    public function complete(Generation $generation, array $data): Generation
    {
        $inputTokens = isset($data['input_tokens']) ? (int) $data['input_tokens'] : $generation->input_tokens;
        $outputTokens = isset($data['output_tokens']) ? (int) $data['output_tokens'] : ($generation->output_tokens ?? 0);
        $actual = $this->pricing->actual($inputTokens, $outputTokens, $generation->size);

        $imageUrl = $data['image_url'] ?? $generation->image_url;
        $storedPath = $generation->stored_image_path;

        // Result URLs are signed and expire in 24h — keep a local copy.
        if ($imageUrl && ! $storedPath) {
            $storedPath = $this->storeImageLocally($generation, $imageUrl);
        }

        $generation->update([
            'status' => Generation::STATUS_COMPLETED,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'actual_price' => $actual['price'],
            'image_url' => $imageUrl,
            'stored_image_path' => $storedPath,
            'error_code' => null,
            'error_message' => null,
        ]);

        return $generation;
    }

    /**
     * Mark a generation failed. Failed generations always cost $0.
     */
    public function fail(Generation $generation, ?string $errorCode = null, ?string $errorMessage = null): Generation
    {
        $generation->update([
            'status' => Generation::STATUS_FAILED,
            'actual_price' => 0,
            'error_code' => $errorCode,
            'error_message' => $errorMessage ?: 'The generation failed. You have not been charged.',
        ]);

        return $generation;
    }

    /**
     * Build a clear, user-facing message from a Pixelforge error body.
     */
    public function friendlyError(?array $error, int $status): string
    {
        $code = $error['code'] ?? null;
        $apiMessage = $error['message'] ?? null;

        return match ($code) {
            'INVALID_PROMPT' => 'Pixelforge rejected the prompt: '.($apiMessage ?? 'the prompt was invalid.'),
            'INSUFFICIENT_BALANCE' => 'The Pixelforge account balance is too low to run this generation. Please top up and try again.',
            'MODERATION_BLOCKED' => 'The prompt was blocked by Pixelforge content moderation. Please rephrase it and try again.',
            'RATE_LIMITED' => 'Pixelforge rate limit reached. Please try again in a moment.',
            default => match ($status) {
                400 => 'Pixelforge rejected the request as invalid. '.($apiMessage ?? ''),
                401 => 'Pixelforge rejected the API key. Please check the PIXELFORGE_API_KEY configuration.',
                402 => 'The Pixelforge account cannot pay for this generation (balance or daily limit). '.($apiMessage ?? ''),
                404 => 'Pixelforge could not find the requested resource.',
                409 => 'This generation conflicts with an earlier submission. Please submit it again.',
                422 => 'Pixelforge could not process the request: '.($apiMessage ?? 'validation failed.'),
                default => $apiMessage ?? 'Pixelforge returned an unexpected error (HTTP '.$status.').',
            },
        };
    }

    private function storeImageLocally(Generation $generation, string $imageUrl): ?string
    {
        try {
            $response = $this->client->downloadImage($imageUrl);

            if (! $response->successful() || $response->body() === '') {
                Log::warning('Pixelforge image download failed', [
                    'generation_id' => $generation->id,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $extension = $this->guessExtension($response->header('Content-Type'), $imageUrl);
            $path = 'generations/'.$generation->id.'.'.$extension;

            Storage::disk('public')->put($path, $response->body());

            return $path;
        } catch (\Throwable $e) {
            // A failed download must not fail the generation itself;
            // the signed URL remains available on the record.
            Log::warning('Pixelforge image download exception', [
                'generation_id' => $generation->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function guessExtension(?string $contentType, string $url): string
    {
        $fromType = match (true) {
            str_contains((string) $contentType, 'image/jpeg') => 'jpg',
            str_contains((string) $contentType, 'image/webp') => 'webp',
            str_contains((string) $contentType, 'image/png') => 'png',
            default => null,
        };

        if ($fromType) {
            return $fromType;
        }

        $pathExtension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return in_array($pathExtension, ['png', 'jpg', 'jpeg', 'webp'], true)
            ? ($pathExtension === 'jpeg' ? 'jpg' : $pathExtension)
            : 'png';
    }
}
