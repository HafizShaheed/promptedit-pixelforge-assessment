<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;

/**
 * Polls Pixelforge for a generation's status until it reaches a terminal
 * state. Complements webhooks: whichever arrives first finalizes the
 * generation (both paths share GenerationService and are idempotent).
 * Polls every 10 seconds, up to ~15 attempts (~2.5 minutes, covering the
 * documented 30–90s generation time with headroom).
 */
class PollGenerationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    private const MAX_ATTEMPTS = 15;

    private const POLL_DELAY_SECONDS = 10;

    public function __construct(
        public Generation $generation,
        public int $pollAttempt = 0,
    ) {}

    public function handle(PixelforgeClient $client, GenerationService $service): void
    {
        $generation = $this->generation->fresh();

        if (! $generation || $generation->isTerminal() || ! $generation->pixelforge_id) {
            return;
        }

        try {
            $response = $client->getGeneration($generation->pixelforge_id);
        } catch (ConnectionException) {
            $this->reschedule($generation);

            return;
        }

        if ($response->status() === 404) {
            // Unknown upstream — keep waiting briefly in case of lag,
            // then stop; webhooks remain the fallback.
            $this->reschedule($generation);

            return;
        }

        if (! $response->successful()) {
            // 429 / 5xx etc. — transient; try again on the next poll.
            $this->reschedule($generation);

            return;
        }

        $data = $response->json('data') ?? $response->json() ?? [];
        $status = $data['status'] ?? null;

        if ($status === Generation::STATUS_COMPLETED) {
            $service->complete($generation, $data);

            return;
        }

        if ($status === Generation::STATUS_FAILED) {
            $error = $data['error'] ?? null;
            $service->fail(
                $generation,
                is_array($error) ? ($error['code'] ?? null) : null,
                is_array($error) ? ($error['message'] ?? null) : null,
            );

            return;
        }

        // queued / processing — reflect the latest status and poll again.
        if (in_array($status, [Generation::STATUS_QUEUED, Generation::STATUS_PROCESSING], true)
            && $generation->status !== $status) {
            $generation->update(['status' => $status]);
        }

        $this->reschedule($generation);
    }

    private function reschedule(Generation $generation): void
    {
        if ($this->pollAttempt + 1 >= self::MAX_ATTEMPTS) {
            return;
        }

        self::dispatch($generation, $this->pollAttempt + 1)
            ->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));
    }
}
