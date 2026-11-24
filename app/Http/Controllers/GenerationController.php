<?php

namespace App\Http\Controllers;

use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Services\PixelforgeClient;
use App\Services\PricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(private readonly PricingService $pricing) {}

    /** Generate page. */
    public function create(Request $request, PixelforgeClient $client): Response
    {
        $balance = null;
        $balanceError = false;

        try {
            $response = $client->getBalance();
            if ($response->successful()) {
                $balance = $response->json();
            } else {
                $balanceError = true;
            }
        } catch (\Throwable) {
            $balanceError = true;
        }

        // Submissions still waiting to reach Pixelforge usually mean the
        // provider is having trouble — surface that on the form.
        $hasPendingSubmissions = $request->user()->generations()
            ->where('status', Generation::STATUS_PENDING_SUBMIT)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->exists();

        return Inertia::render('Generations/Create', [
            'sizes' => PricingService::sizes(),
            'balance' => $balance,
            'balanceError' => $balanceError,
            'hasPendingSubmissions' => $hasPendingSubmissions,
        ]);
    }

    /** Queue a new generation. All Pixelforge calls happen in the job. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'min:1', 'max:4000'],
            'size' => ['required', 'string', 'in:'.implode(',', PricingService::sizes())],
            'quality' => ['nullable', 'string', 'in:standard,hd'],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['nullable', 'url', 'max:2048'],
        ]);

        $referenceImages = array_values(array_filter(
            $validated['reference_images'] ?? [],
            fn ($url) => is_string($url) && trim($url) !== '',
        ));

        $estimate = $this->pricing->estimate(
            $validated['prompt'],
            $validated['size'],
            count($referenceImages),
        );

        $generation = $request->user()->generations()->create([
            'prompt' => $validated['prompt'],
            'size' => $validated['size'],
            'quality' => $validated['quality'] ?? 'standard',
            'reference_images' => $referenceImages,
            'status' => Generation::STATUS_PENDING_SUBMIT,
            'input_tokens' => $estimate['input_tokens'],
            'estimated_price' => $estimate['price'],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        SubmitGenerationJob::dispatch($generation);

        return redirect()->route('generations.index')
            ->with('success', 'Your generation has been queued. The final price is confirmed when it completes.');
    }

    /** History page: the signed-in user's generations, newest first. */
    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()
            ->latest()
            ->paginate(20)
            ->through(fn (Generation $generation) => $this->present($generation));

        return Inertia::render('Generations/Index', [
            'generations' => $generations,
        ]);
    }

    private function present(Generation $generation): array
    {
        return [
            'id' => $generation->id,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'status' => $generation->status,
            'input_tokens' => $generation->input_tokens,
            'output_tokens' => $generation->output_tokens,
            'estimated_price' => $generation->estimated_price,
            'actual_price' => $generation->actual_price,
            'image_url' => $generation->stored_image_path
                ? Storage::disk('public')->url($generation->stored_image_path)
                : $generation->image_url,
            'error_message' => $generation->error_message,
            'created_at' => $generation->created_at?->toIso8601String(),
        ];
    }
}
