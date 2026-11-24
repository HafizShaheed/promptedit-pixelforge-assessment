<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP wrapper around the Pixelforge API. The API key never leaves
 * the server: it is only ever sent as a Bearer header from here.
 */
class PixelforgeClient
{
    public const TIMEOUT_SECONDS = 30;

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.pixelforge.api_url'), '/'))
            ->withToken((string) config('services.pixelforge.api_key'))
            ->timeout(self::TIMEOUT_SECONDS)
            ->acceptJson();
    }

    /**
     * POST /images/generations with an Idempotency-Key header.
     *
     * @throws \Illuminate\Http\Client\ConnectionException on timeout / network failure
     */
    public function createGeneration(array $payload, string $idempotencyKey): Response
    {
        return $this->http()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/images/generations', $payload);
    }

    /**
     * GET /images/generations/{id}
     *
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public function getGeneration(string $pixelforgeId): Response
    {
        return $this->http()->get('/images/generations/'.$pixelforgeId);
    }

    /**
     * GET /balance
     *
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public function getBalance(): Response
    {
        return $this->http()->get('/balance');
    }

    /**
     * Download a (signed, expiring) result image URL. Tries without auth
     * first (signed URLs are self-contained); falls back to the Bearer key
     * when the URL belongs to the API host and the plain request failed.
     *
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public function downloadImage(string $url): Response
    {
        $response = Http::timeout(self::TIMEOUT_SECONDS)->get($url);

        if (! $response->successful()
            && str_starts_with($url, rtrim((string) config('services.pixelforge.api_url'), '/'))) {
            $response = $this->http()->get($url);
        }

        return $response;
    }
}
