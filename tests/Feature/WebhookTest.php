<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pixelforge.webhook_secret' => self::SECRET]);
        Storage::fake('public');

        Http::fake([
            'https://cdn.pixelforge.test/*' => Http::response('fake-png-bytes', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);
    }

    private function makeGeneration(array $attributes = []): Generation
    {
        $user = User::factory()->create();

        return $user->generations()->create(array_merge([
            'prompt' => 'a watercolour of a lighthouse at dusk',
            'size' => '1024x1024',
            'quality' => 'standard',
            'reference_images' => [],
            'status' => Generation::STATUS_PROCESSING,
            'pixelforge_id' => 'gen_01JWEBHOOK',
            'input_tokens' => 8,
            'estimated_price' => 0.18,
            'idempotency_key' => (string) Str::uuid(),
        ], $attributes));
    }

    private function completedPayload(string $eventId, string $createdAt, string $generationId = 'gen_01JWEBHOOK'): array
    {
        return [
            'id' => $eventId,
            'type' => 'generation.completed',
            'created_at' => $createdAt,
            'data' => [
                'id' => $generationId,
                'status' => 'completed',
                'prompt' => 'a watercolour of a lighthouse at dusk',
                'size' => '1024x1024',
                'quality' => 'standard',
                'input_tokens' => 8,
                'output_tokens' => 912,
                'image_url' => 'https://cdn.pixelforge.test/result.png',
                'completed_at' => $createdAt,
            ],
        ];
    }

    private function failedPayload(string $eventId, string $createdAt, string $generationId = 'gen_01JWEBHOOK'): array
    {
        return [
            'id' => $eventId,
            'type' => 'generation.failed',
            'created_at' => $createdAt,
            'data' => [
                'id' => $generationId,
                'status' => 'failed',
                'prompt' => 'a watercolour of a lighthouse at dusk',
                'size' => '1024x1024',
                'quality' => 'standard',
                'input_tokens' => 8,
                'output_tokens' => null,
                'image_url' => null,
                'error' => ['code' => 'TIMEOUT', 'message' => 'The generation timed out.'],
                'completed_at' => $createdAt,
            ],
        ];
    }

    private function postWebhook(array $payload, ?int $timestamp = null, ?string $signatureOverride = null)
    {
        $body = json_encode($payload);
        $timestamp ??= time();
        $signature = $signatureOverride
            ?? 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PIXELFORGE_SIGNATURE' => $signature,
            'HTTP_X_PIXELFORGE_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_PIXELFORGE_EVENT_ID' => $payload['id'] ?? '',
        ], $body);
    }

    public function test_valid_completed_event_applies_and_records_price(): void
    {
        $generation = $this->makeGeneration();
        $createdAt = now()->toIso8601String();

        $response = $this->postWebhook($this->completedPayload('evt_completed_1', $createdAt));

        $response->assertOk();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_COMPLETED, $generation->status);
        $this->assertSame(912, $generation->output_tokens);

        // Price from API-reported tokens (8 in / 912 out @ 1024x1024).
        $expected = app(PricingService::class)->actual(8, 912, '1024x1024');
        $this->assertEqualsWithDelta($expected['price'], $generation->actual_price, 0.00001);

        $this->assertNotNull($generation->stored_image_path);
        Storage::disk('public')->assertExists($generation->stored_image_path);
        $this->assertNotNull($generation->last_webhook_event_at);
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_valid_failed_event_marks_failed_with_zero_price(): void
    {
        $generation = $this->makeGeneration();

        $response = $this->postWebhook($this->failedPayload('evt_failed_1', now()->toIso8601String()));

        $response->assertOk();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertEquals(0.0, (float) $generation->actual_price);
        $this->assertSame('TIMEOUT', $generation->error_code);
        $this->assertSame('The generation timed out.', $generation->error_message);
    }

    public function test_duplicate_event_delivery_is_a_noop(): void
    {
        $generation = $this->makeGeneration();
        $payload = $this->completedPayload('evt_dup_1', now()->toIso8601String());

        $this->postWebhook($payload)->assertOk();
        $priceAfterFirst = $generation->fresh()->actual_price;

        $this->postWebhook($payload)->assertOk();

        $this->assertSame(1, WebhookEvent::where('event_id', 'evt_dup_1')->count());
        $generation->refresh();
        $this->assertSame(Generation::STATUS_COMPLETED, $generation->status);
        $this->assertEqualsWithDelta($priceAfterFirst, $generation->actual_price, 0.00001);
    }

    public function test_older_completed_event_does_not_overwrite_newer_failed_state(): void
    {
        $generation = $this->makeGeneration();
        $newer = now();
        $older = now()->subMinutes(5);

        // Newer failed event lands first.
        $this->postWebhook($this->failedPayload('evt_oo_1', $newer->toIso8601String()))->assertOk();
        $this->assertSame(Generation::STATUS_FAILED, $generation->fresh()->status);

        // Older completed event arrives late — must be ignored.
        $this->postWebhook($this->completedPayload('evt_oo_2', $older->toIso8601String()))->assertOk();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertEquals(0.0, (float) $generation->actual_price);
    }

    public function test_older_failed_event_does_not_regress_completed_state(): void
    {
        $generation = $this->makeGeneration();
        $newer = now();
        $older = now()->subMinutes(5);

        $this->postWebhook($this->completedPayload('evt_oo_3', $newer->toIso8601String()))->assertOk();
        $this->assertSame(Generation::STATUS_COMPLETED, $generation->fresh()->status);

        $this->postWebhook($this->failedPayload('evt_oo_4', $older->toIso8601String()))->assertOk();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_COMPLETED, $generation->status);
        $this->assertGreaterThan(0, (float) $generation->actual_price);
    }

    public function test_newer_completed_event_supersedes_failed_state(): void
    {
        $generation = $this->makeGeneration();
        $older = now()->subMinutes(5);
        $newer = now();

        $this->postWebhook($this->failedPayload('evt_sup_1', $older->toIso8601String()))->assertOk();
        $this->assertSame(Generation::STATUS_FAILED, $generation->fresh()->status);

        $this->postWebhook($this->completedPayload('evt_sup_2', $newer->toIso8601String()))->assertOk();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_COMPLETED, $generation->status);
        $this->assertGreaterThan(0, (float) $generation->actual_price);
    }

    public function test_bad_signature_returns_401_and_changes_nothing(): void
    {
        $generation = $this->makeGeneration();
        $payload = $this->completedPayload('evt_bad_1', now()->toIso8601String());

        $response = $this->postWebhook($payload, signatureOverride: 't='.time().',v1='.str_repeat('0', 64));

        $response->assertStatus(401);
        $this->assertSame(Generation::STATUS_PROCESSING, $generation->fresh()->status);
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_missing_signature_returns_401(): void
    {
        $this->makeGeneration();
        $body = json_encode($this->completedPayload('evt_bad_2', now()->toIso8601String()));

        $response = $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertStatus(401);
    }

    public function test_stale_timestamp_returns_401_even_with_valid_signature(): void
    {
        $generation = $this->makeGeneration();
        $payload = $this->completedPayload('evt_stale_1', now()->toIso8601String());
        $staleTimestamp = time() - 3600; // 1 hour old, limit is 5 minutes

        $response = $this->postWebhook($payload, timestamp: $staleTimestamp);

        $response->assertStatus(401);
        $this->assertSame(Generation::STATUS_PROCESSING, $generation->fresh()->status);
    }
}
