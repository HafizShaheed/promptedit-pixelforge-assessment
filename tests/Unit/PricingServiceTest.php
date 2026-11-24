<?php

namespace Tests\Unit;

use App\Services\PricingService;
use PHPUnit\Framework\TestCase;

class PricingServiceTest extends TestCase
{
    private PricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = new PricingService;
    }

    public function test_prompt_tokens_round_up_per_four_characters(): void
    {
        $this->assertSame(10, $this->pricing->promptTokens(str_repeat('a', 40)));
        $this->assertSame(11, $this->pricing->promptTokens(str_repeat('a', 41)));
        $this->assertSame(1, $this->pricing->promptTokens('a'));
    }

    public function test_docs_worked_example_estimate(): void
    {
        // Docs: 40-char prompt, 1024x1024, no refs → input 10 tokens,
        // estimated output 900 → (10/1000 × $0.040) + (900/1000 × $0.160)
        // = $0.1444 before markup; user estimate = × 1.25 = $0.1805.
        $estimate = $this->pricing->estimate(str_repeat('a', 40), '1024x1024', 0);

        $this->assertSame(10, $estimate['input_tokens']);
        $this->assertSame(900, $estimate['output_tokens']);
        $this->assertEqualsWithDelta(0.1444, $estimate['cost'], 0.00001);
        $this->assertEqualsWithDelta(0.1805, $estimate['price'], 0.00001);
    }

    public function test_reference_images_add_1600_input_tokens_each(): void
    {
        $prompt = str_repeat('a', 40);

        $this->assertSame(10, $this->pricing->inputTokens($prompt, 0));
        $this->assertSame(1610, $this->pricing->inputTokens($prompt, 1));
        $this->assertSame(6410, $this->pricing->inputTokens($prompt, 4));

        // 1 ref at 1024x1024: (1610/1000 × 0.040) + (900/1000 × 0.160)
        // = 0.0644 + 0.144 = $0.2084; user price × 1.25 = $0.2605.
        $estimate = $this->pricing->estimate($prompt, '1024x1024', 1);
        $this->assertEqualsWithDelta(0.2084, $estimate['cost'], 0.00001);
        $this->assertEqualsWithDelta(0.2605, $estimate['price'], 0.00001);
    }

    public function test_larger_sizes_use_higher_rates(): void
    {
        // 1000 in / 1000 out at the large-size rates: 0.050 + 0.200 = $0.25.
        foreach (['1024x1536', '1536x1024'] as $size) {
            $this->assertEqualsWithDelta(0.25, $this->pricing->cost(1000, 1000, $size), 0.00001);
            $actual = $this->pricing->actual(1000, 1000, $size);
            $this->assertEqualsWithDelta(0.3125, $actual['price'], 0.00001);
        }

        // Square size for comparison: 0.040 + 0.160 = $0.20.
        $this->assertEqualsWithDelta(0.20, $this->pricing->cost(1000, 1000, '1024x1024'), 0.00001);
    }

    public function test_actual_cost_uses_reported_output_tokens(): void
    {
        // input 10, output 912 at 1024x1024:
        // (10/1000 × 0.040) + (912/1000 × 0.160) = 0.0004 + 0.14592 = $0.14632
        // user price × 1.25 = $0.1829.
        $actual = $this->pricing->actual(10, 912, '1024x1024');

        $this->assertEqualsWithDelta(0.14632, $actual['cost'], 0.00001);
        $this->assertEqualsWithDelta(0.1829, $actual['price'], 0.00001);
    }

    public function test_markup_is_twenty_five_percent(): void
    {
        $this->assertEqualsWithDelta(1.25, $this->pricing->userPrice(1.0), 0.00001);
        $this->assertEqualsWithDelta(0.1805, $this->pricing->userPrice(0.1444), 0.00001);
    }
}
