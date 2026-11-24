<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    balance: { type: Object, default: null },
    error: { type: String, default: null },
});

const money = (v) =>
    v === null || v === undefined ? '—' : '$' + Number(v).toFixed(2);
</script>

<template>
    <Head title="Balance" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Pixelforge Balance
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <div
                    v-if="error"
                    class="rounded-lg border border-red-300 bg-red-50 p-6 text-red-800"
                >
                    <p class="font-bold">Balance unavailable</p>
                    <p class="mt-1 text-sm">{{ error }}</p>
                    <Link
                        :href="route('balance.index')"
                        class="mt-3 inline-block text-sm font-medium text-red-900 underline"
                    >
                        Try again
                    </Link>
                </div>

                <template v-else-if="balance">
                    <div
                        v-if="balance.is_low"
                        class="mb-6 rounded-lg border-2 border-red-400 bg-red-50 p-5 text-red-800"
                    >
                        <p class="text-lg font-bold">⚠ Low balance warning</p>
                        <p class="text-sm">
                            The Pixelforge balance is below the
                            {{ money(balance.low_balance_threshold) }} threshold.
                            New generations may fail with an insufficient-balance
                            error until the account is topped up.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="bg-white p-6 shadow sm:rounded-lg">
                            <p class="text-sm text-gray-500">Current balance</p>
                            <p
                                class="mt-1 text-3xl font-bold"
                                :class="balance.is_low ? 'text-red-600' : 'text-gray-900'"
                            >
                                {{ money(balance.balance) }}
                                <span class="text-base font-normal text-gray-400">
                                    {{ balance.currency }}
                                </span>
                            </p>
                        </div>
                        <div class="bg-white p-6 shadow sm:rounded-lg">
                            <p class="text-sm text-gray-500">Daily remaining</p>
                            <p class="mt-1 text-3xl font-bold text-gray-900">
                                {{ money(balance.daily_remaining) }}
                            </p>
                        </div>
                        <div class="bg-white p-6 shadow sm:rounded-lg">
                            <p class="text-sm text-gray-500">Daily limit</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900">
                                {{ money(balance.daily_limit) }}
                            </p>
                        </div>
                        <div class="bg-white p-6 shadow sm:rounded-lg">
                            <p class="text-sm text-gray-500">Spent today</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900">
                                {{ money(balance.daily_spent) }}
                            </p>
                        </div>
                    </div>

                    <p class="mt-4 text-xs text-gray-400">
                        Fetched live from Pixelforge just now. Low-balance
                        threshold: {{ money(balance.low_balance_threshold) }}.
                    </p>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
