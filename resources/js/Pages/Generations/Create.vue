<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    sizes: { type: Array, default: () => ['1024x1024', '1024x1536', '1536x1024'] },
    balance: { type: Object, default: null },
    balanceError: { type: Boolean, default: false },
    hasPendingSubmissions: { type: Boolean, default: false },
});

// Same pricing rules as App\Services\PricingService — keep in sync.
const RATES = {
    '1024x1024': { input: 0.04, output: 0.16 },
    '1024x1536': { input: 0.05, output: 0.2 },
    '1536x1024': { input: 0.05, output: 0.2 },
};
const MARKUP = 1.25;
const ESTIMATED_OUTPUT_TOKENS = 900;
const REFERENCE_IMAGE_TOKENS = 1600;

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'standard',
    reference_images: ['', '', '', ''],
});

const promptChars = computed(() => Array.from(form.prompt).length);
const referenceCount = computed(
    () => form.reference_images.filter((u) => u && u.trim() !== '').length,
);

const estimate = computed(() => {
    const rates = RATES[form.size] ?? RATES['1024x1024'];
    const promptTokens = Math.ceil(promptChars.value / 4);
    const inputTokens = promptTokens + REFERENCE_IMAGE_TOKENS * referenceCount.value;
    const cost =
        (inputTokens / 1000) * rates.input +
        (ESTIMATED_OUTPUT_TOKENS / 1000) * rates.output;
    return {
        inputTokens,
        outputTokens: ESTIMATED_OUTPUT_TOKENS,
        price: cost * MARKUP,
    };
});

const money = (v) =>
    v === null || v === undefined ? '—' : '$' + Number(v).toFixed(4);

const submit = () => {
    form
        .transform((data) => ({
            ...data,
            reference_images: data.reference_images.filter(
                (u) => u && u.trim() !== '',
            ),
        }))
        .post(route('generations.store'));
};
</script>

<template>
    <Head title="Generate Image" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Generate an Image
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <!-- Low balance warning -->
                <div
                    v-if="balance && balance.is_low"
                    class="mb-6 rounded-lg border-2 border-red-300 bg-red-50 p-4 text-red-800"
                >
                    <p class="font-bold">⚠ Low Pixelforge balance</p>
                    <p class="text-sm">
                        Current balance is {{ money(balance.balance) }} and
                        today's remaining spend is
                        {{ money(balance.daily_remaining) }}. Generations may
                        fail until the account is topped up.
                    </p>
                </div>

                <!-- Balance unavailable -->
                <div
                    v-else-if="balanceError"
                    class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-800"
                >
                    <p class="text-sm font-medium">
                        We couldn't check the Pixelforge balance just now — the
                        provider may be unavailable. You can still submit; the
                        generation runs in the background.
                    </p>
                </div>

                <!-- Provider unavailable / retrying notice -->
                <div
                    v-if="hasPendingSubmissions"
                    class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-800"
                >
                    <p class="text-sm font-medium">
                        ⏳ Some of your recent submissions are still being sent
                        to Pixelforge — the provider appears to be unavailable
                        or slow. They will keep retrying automatically in the
                        background; check History for progress.
                    </p>
                </div>

                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <form @submit.prevent="submit" class="space-y-6">
                        <div>
                            <InputLabel for="prompt" value="Prompt" />
                            <textarea
                                id="prompt"
                                v-model="form.prompt"
                                rows="4"
                                maxlength="4000"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="a watercolour of a lighthouse at dusk"
                            />
                            <div class="mt-1 flex justify-between text-xs text-gray-500">
                                <span>Describe the image you want to generate.</span>
                                <span>{{ promptChars }} / 4000 characters</span>
                            </div>
                            <InputError :message="form.errors.prompt" class="mt-2" />
                        </div>

                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <div>
                                <InputLabel for="size" value="Size" />
                                <select
                                    id="size"
                                    v-model="form.size"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                >
                                    <option v-for="s in sizes" :key="s" :value="s">
                                        {{ s }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.size" class="mt-2" />
                            </div>

                            <div>
                                <InputLabel for="quality" value="Quality" />
                                <select
                                    id="quality"
                                    v-model="form.quality"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                >
                                    <option value="standard">Standard</option>
                                    <option value="hd">HD</option>
                                </select>
                                <InputError :message="form.errors.quality" class="mt-2" />
                            </div>
                        </div>

                        <div>
                            <InputLabel value="Reference image URLs (optional, up to 4)" />
                            <div class="mt-1 space-y-2">
                                <input
                                    v-for="(url, i) in form.reference_images"
                                    :key="i"
                                    v-model="form.reference_images[i]"
                                    type="url"
                                    :placeholder="`https://example.com/reference-${i + 1}.png`"
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                Each reference image adds 1,600 input tokens to the price.
                            </p>
                            <InputError
                                :message="form.errors.reference_images"
                                class="mt-2"
                            />
                        </div>

                        <!-- Live estimate -->
                        <div class="rounded-lg bg-indigo-50 p-4">
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm font-medium text-indigo-900">
                                    Estimated price
                                </span>
                                <span class="text-2xl font-bold text-indigo-900">
                                    {{ money(estimate.price) }}
                                </span>
                            </div>
                            <p class="mt-1 text-xs text-indigo-700">
                                Based on ~{{ estimate.inputTokens }} input tokens
                                and {{ estimate.outputTokens }} output tokens,
                                including a 25% service markup. The final price
                                is confirmed from the actual token counts when
                                the generation completes — failed generations
                                cost $0.
                            </p>
                            <p v-if="balance" class="mt-1 text-xs text-indigo-700">
                                Account balance: {{ money(balance.balance) }} ·
                                Daily remaining: {{ money(balance.daily_remaining) }}
                            </p>
                        </div>

                        <div
                            v-if="form.errors && Object.keys(form.errors).length"
                            class="rounded-lg bg-red-50 p-3 text-sm text-red-700"
                        >
                            Please fix the highlighted fields and try again.
                        </div>

                        <div class="flex items-center justify-end">
                            <PrimaryButton :disabled="form.processing || !form.prompt">
                                {{ form.processing ? 'Queuing…' : 'Generate Image' }}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
