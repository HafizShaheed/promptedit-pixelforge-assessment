<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Throwable;

/**
 * Submits a Generation to Pixelforge (POST /images/generations).
 *
 * Retry policy (per API docs):
 *  - 429 / 500 / 502 / 503 and network timeouts are transient: the job
 *    throws so the queue retries it with exponential backoff, max 5 tries.
 *  - 400 / 401 / 402 / 404 / 409 / 422 are permanent: the generation is
 *    marked failed immediately with a clear message and a $0 charge.
 * The generation's UUID is sent as the Idempotency-Key, so a retry after
 * an ambiguous failure can never create a duplicate generation upstream.
 */
class SubmitGenerationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> seconds between attempts */
    public array $backoff = [10, 30, 60, 120, 300];

    private const RETRYABLE_STATUSES = [429, 500, 502, 503];

    public function __construct(public Generation $generation) {}

    public function handle(PixelforgeClient $client, GenerationService $service): void
    {
        $generation = $this->generation->fresh();

        if (! $generation || $generation->isTerminal()) {
            return;
        }

        // Already accepted upstream (e.g. a previous attempt succeeded but
        // its response was lost) — just make sure polling is running.
        if ($generation->pixelforge_id) {
            PollGenerationJob::dispatch($generation)->delay(now()->addSeconds(5));

            return;
        }

        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
        ];

        if (! empty($generation->reference_images)) {
            $payload['reference_images'] = array_values($generation->reference_images);
        }

        $webhookUrl = $this->webhookUrl();
        if ($webhookUrl) {
            $payload['webhook_url'] = $webhookUrl;
        }

        try {
            $response = $client->createGeneration($payload, $generation->idempotency_key);
        } catch (ConnectionException $e) {
            // Timeout / network failure — transient, let the queue retry.
            throw new RuntimeException('Pixelforge is unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() === 202 || $response->successful()) {
            $data = $response->json('data') ?? $response->json() ?? [];

            $generation->update([
                'pixelforge_id' => $data['id'] ?? null,
                'input_tokens' => isset($data['input_tokens']) ? (int) $data['input_tokens'] : $generation->input_tokens,
                'status' => Generation::STATUS_QUEUED,
                'error_code' => null,
                'error_message' => null,
            ]);

            PollGenerationJob::dispatch($generation)->delay(now()->addSeconds(10));

            return;
        }

        if (in_array($response->status(), self::RETRYABLE_STATUSES, true)) {
            throw new RuntimeException('Pixelforge temporarily unavailable (HTTP '.$response->status().').');
        }

        // Permanent error — fail the generation, charge $0, do not retry.
        $error = $response->json('error');
        $service->fail(
            $generation,
            is_array($error) ? ($error['code'] ?? 'HTTP_'.$response->status()) : 'HTTP_'.$response->status(),
            $service->friendlyError(is_array($error) ? $error : null, $response->status()),
        );
    }

    /**
     * Called after the final retry is exhausted. Only fail the generation
     * if Pixelforge never accepted it; an accepted generation may still
     * complete via polling/webhooks.
     */
    public function failed(?Throwable $exception): void
    {
        $generation = $this->generation->fresh();

        if ($generation && ! $generation->pixelforge_id && ! $generation->isTerminal()) {
            $generation->update([
                'status' => Generation::STATUS_FAILED,
                'actual_price' => 0,
                'error_code' => 'PROVIDER_UNAVAILABLE',
                'error_message' => 'Pixelforge is currently unavailable, so this generation could not be submitted. You have not been charged — please try again shortly.',
            ]);
        }
    }

    private function webhookUrl(): ?string
    {
        $appUrl = (string) config('app.url');

        // Webhooks can only be delivered to a public https URL.
        return str_starts_with($appUrl, 'https://')
            ? rtrim($appUrl, '/').'/webhooks/pixelforge'
            : null;
    }
}
