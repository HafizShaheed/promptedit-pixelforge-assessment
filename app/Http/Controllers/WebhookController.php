<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\GenerationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /webhooks/pixelforge
 *
 * Signature: X-Pixelforge-Signature: t=<unix>,v1=<hex> where
 * v1 = HMAC-SHA256(webhook_secret, "<unix>.<raw body>").
 * Missing/invalid signatures and timestamps older than 5 minutes → 401.
 *
 * Delivery is at-least-once and may be out of order, so:
 *  - events are deduped by event id (WebhookEvent),
 *  - an event is only applied when it is newer than the last applied
 *    webhook event for that generation,
 *  - a completed generation never regresses; a failed generation may be
 *    superseded by a *newer* completed event.
 */
class WebhookController extends Controller
{
    private const MAX_TIMESTAMP_SKEW_SECONDS = 300;

    public function __invoke(Request $request, GenerationService $service): Response
    {
        $rawBody = $request->getContent();

        if (! $this->signatureIsValid($request, $rawBody)) {
            return response('Invalid signature', 401);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload) || empty($payload['type'])) {
            return response('Invalid payload', 400);
        }

        $eventId = $payload['id'] ?? $request->header('X-Pixelforge-Event-Id');
        $data = $payload['data'] ?? [];
        $pixelforgeId = is_array($data) ? ($data['id'] ?? null) : null;
        $eventTime = $this->parseEventTime($payload['created_at'] ?? null);

        // Dedupe: the same event delivered twice is a no-op.
        if ($eventId) {
            $event = WebhookEvent::firstOrCreate(
                ['event_id' => $eventId],
                [
                    'type' => $payload['type'],
                    'pixelforge_generation_id' => $pixelforgeId,
                    'payload_created_at' => $eventTime,
                ],
            );

            if (! $event->wasRecentlyCreated) {
                return response('Duplicate event ignored', 200);
            }
        }

        if (! $pixelforgeId) {
            return response('No generation referenced', 200);
        }

        $generation = Generation::where('pixelforge_id', $pixelforgeId)->first();

        if (! $generation) {
            return response('Unknown generation', 200);
        }

        // Out-of-order guard: never apply an event older than the newest
        // state we already have from webhooks.
        if ($eventTime && $generation->last_webhook_event_at
            && $eventTime->lessThanOrEqualTo($generation->last_webhook_event_at)) {
            return response('Stale event ignored', 200);
        }

        // A completed generation is final and must never regress.
        if ($generation->status === Generation::STATUS_COMPLETED
            && $payload['type'] !== 'generation.completed') {
            return response('Generation already completed', 200);
        }

        if ($eventTime) {
            $generation->last_webhook_event_at = $eventTime;
        }

        match ($payload['type']) {
            'generation.completed' => $service->complete($generation, is_array($data) ? $data : []),
            'generation.failed' => $service->fail(
                $generation,
                is_array($data['error'] ?? null) ? ($data['error']['code'] ?? null) : null,
                is_array($data['error'] ?? null) ? ($data['error']['message'] ?? null) : null,
            ),
            default => null,
        };

        if ($eventTime) {
            // complete()/fail() update the model; make sure the webhook
            // watermark is persisted alongside their changes.
            $generation->forceFill(['last_webhook_event_at' => $eventTime])->save();
        }

        return response('OK', 200);
    }

    private function signatureIsValid(Request $request, string $rawBody): bool
    {
        $secret = (string) config('services.pixelforge.webhook_secret');
        $signatureHeader = (string) $request->header('X-Pixelforge-Signature', '');

        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $timestamp = null;
        $signature = null;

        foreach (explode(',', $signatureHeader) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) {
                $timestamp = substr($part, 2);
            } elseif (str_starts_with($part, 'v1=')) {
                $signature = substr($part, 3);
            }
        }

        // Fall back to the dedicated timestamp header when t= is absent.
        $timestamp ??= $request->header('X-Pixelforge-Timestamp');

        if (! $timestamp || ! $signature || ! is_numeric($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::MAX_TIMESTAMP_SKEW_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    private function parseEventTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
