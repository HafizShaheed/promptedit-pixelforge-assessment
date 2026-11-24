<?php

namespace Tests\Feature;

use App\Jobs\PollGenerationJob;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\PixelforgeClient;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GenerationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.pixelforge.promptedit.dev/v1';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('public');
    }

    private function makeGeneration(User $user, array $attributes = []): Generation
    {
        $pricing = app(PricingService::class);
        $prompt = $attributes['prompt'] ?? str_repeat('a', 40);
        $estimate = $pricing->estimate($prompt, $attributes['size'] ?? '1024x1024', 0);

        return $user->generations()->create(array_merge([
            'prompt' => $prompt,
            'size' => '1024x1024',
            'quality' => 'standard',
            'reference_images' => [],
            'status' => Generation::STATUS_PENDING_SUBMIT,
            'input_tokens' => $estimate['input_tokens'],
            'estimated_price' => $estimate['price'],
            'idempotency_key' => (string) Str::uuid(),
        ], $attributes));
    }

    public function test_store_creates_generation_with_estimate_and_dispatches_submit_job(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => str_repeat('a', 40),
            'size' => '1024x1024',
            'quality' => 'standard',
            'reference_images' => [],
        ]);

        $response->assertRedirect(route('generations.index'));

        $generation = Generation::first();
        $this->assertNotNull($generation);
        $this->assertSame(Generation::STATUS_PENDING_SUBMIT, $generation->status);
        $this->assertSame(10, $generation->input_tokens);
        // Docs worked example: estimate $0.1444 × 1.25 markup.
        $this->assertEqualsWithDelta(0.1805, $generation->estimated_price, 0.00001);
        $this->assertNotEmpty($generation->idempotency_key);

        Queue::assertPushed(SubmitGenerationJob::class);
    }

    public function test_submit_then_poll_completes_with_actual_price_and_stored_image(): void
    {
        $user = User::factory()->create();
        $generation = $this->makeGeneration($user);

        Http::fake([
            self::API.'/images/generations' => Http::response([
                'id' => 'gen_01JTEST',
                'status' => 'queued',
                'input_tokens' => 10,
                'output_tokens' => null,
                'image_url' => null,
            ], 202),
            self::API.'/images/generations/gen_01JTEST' => Http::response([
                'id' => 'gen_01JTEST',
                'status' => 'completed',
                'input_tokens' => 10,
                'output_tokens' => 912,
                'image_url' => 'https://cdn.pixelforge.test/result.png',
            ], 200),
            'https://cdn.pixelforge.test/*' => Http::response('fake-png-bytes', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        // --- Submit ---
        (new SubmitGenerationJob($generation))->handle(
            app(PixelforgeClient::class),
            app(GenerationService::class),
        );

        $generation->refresh();
        $this->assertSame('gen_01JTEST', $generation->pixelforge_id);
        $this->assertSame(Generation::STATUS_QUEUED, $generation->status);
        $this->assertSame(10, $generation->input_tokens);
        Queue::assertPushed(PollGenerationJob::class);

        // Idempotency-Key header must be the generation's UUID.
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $generation->idempotency_key));

        // --- Poll ---
        (new PollGenerationJob($generation))->handle(
            app(PixelforgeClient::class),
            app(GenerationService::class),
        );

        $generation->refresh();
        $this->assertSame(Generation::STATUS_COMPLETED, $generation->status);
        $this->assertSame(912, $generation->output_tokens);

        // Actual price from reported tokens: cost $0.14632 × 1.25 = $0.1829.
        $expected = app(PricingService::class)->actual(10, 912, '1024x1024');
        $this->assertEqualsWithDelta($expected['price'], $generation->actual_price, 0.00001);
        $this->assertEqualsWithDelta(0.1829, $generation->actual_price, 0.00001);

        // Image downloaded and stored locally (signed URLs expire in 24h).
        $this->assertNotNull($generation->stored_image_path);
        Storage::disk('public')->assertExists($generation->stored_image_path);
    }

    public function test_poll_failed_generation_charges_zero(): void
    {
        $user = User::factory()->create();
        $generation = $this->makeGeneration($user, [
            'status' => Generation::STATUS_PROCESSING,
            'pixelforge_id' => 'gen_FAILED1',
        ]);

        Http::fake([
            self::API.'/images/generations/gen_FAILED1' => Http::response([
                'id' => 'gen_FAILED1',
                'status' => 'failed',
                'input_tokens' => 10,
                'output_tokens' => null,
                'image_url' => null,
                'error' => ['code' => 'TIMEOUT', 'message' => 'The generation timed out.'],
            ], 200),
        ]);

        (new PollGenerationJob($generation))->handle(
            app(PixelforgeClient::class),
            app(GenerationService::class),
        );

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertEquals(0.0, (float) $generation->actual_price);
        $this->assertSame('TIMEOUT', $generation->error_code);
    }

    public function test_submit_permanent_error_fails_immediately_with_zero_charge(): void
    {
        $user = User::factory()->create();
        $generation = $this->makeGeneration($user);

        Http::fake([
            self::API.'/images/generations' => Http::response([
                'error' => ['code' => 'INSUFFICIENT_BALANCE', 'message' => 'Balance too low.'],
            ], 402),
        ]);

        (new SubmitGenerationJob($generation))->handle(
            app(PixelforgeClient::class),
            app(GenerationService::class),
        );

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertEquals(0.0, (float) $generation->actual_price);
        $this->assertNull($generation->pixelforge_id);
        $this->assertNotEmpty($generation->error_message);
    }

    public function test_submit_retryable_error_throws_for_queue_retry(): void
    {
        $user = User::factory()->create();
        $generation = $this->makeGeneration($user);

        Http::fake([
            self::API.'/images/generations' => Http::response([
                'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Oops.'],
            ], 503),
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            (new SubmitGenerationJob($generation))->handle(
                app(PixelforgeClient::class),
                app(GenerationService::class),
            );
        } finally {
            // Not marked failed while retries remain — still pending submit.
            $this->assertSame(
                Generation::STATUS_PENDING_SUBMIT,
                $generation->fresh()->status,
            );
        }
    }

    public function test_submit_job_failed_hook_marks_unaccepted_generation_failed(): void
    {
        $user = User::factory()->create();
        $generation = $this->makeGeneration($user);

        (new SubmitGenerationJob($generation))->failed(new \RuntimeException('boom'));

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertEquals(0.0, (float) $generation->actual_price);
        $this->assertSame('PROVIDER_UNAVAILABLE', $generation->error_code);
    }
}
