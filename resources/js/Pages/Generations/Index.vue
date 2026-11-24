<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    generations: { type: Object, required: true },
});

const IN_PROGRESS = ['pending_submit', 'queued', 'processing'];

const hasInProgress = computed(() =>
    (props.generations.data ?? []).some((g) => IN_PROGRESS.includes(g.status)),
);

let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (hasInProgress.value) {
            router.reload({ only: ['generations'] });
        }
    }, 4000);
});
onUnmounted(() => {
    if (timer) clearInterval(timer);
});

const money = (v) =>
    v === null || v === undefined ? '—' : '$' + Number(v).toFixed(4);

const badgeClass = (status) =>
    ({
        pending_submit: 'bg-amber-100 text-amber-800',
        queued: 'bg-sky-100 text-sky-800',
        processing: 'bg-indigo-100 text-indigo-800',
        completed: 'bg-green-100 text-green-800',
        failed: 'bg-red-100 text-red-800',
    })[status] ?? 'bg-gray-100 text-gray-800';

const statusLabel = (status) =>
    ({
        pending_submit: 'Submitting…',
        queued: 'Queued',
        processing: 'Generating…',
        completed: 'Completed',
        failed: 'Failed',
    })[status] ?? status;

const formatDate = (iso) =>
    iso ? new Date(iso).toLocaleString() : '—';
</script>

<template>
    <Head title="History" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Generation History
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div
                    v-if="$page.props.flash && $page.props.flash.success"
                    class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-800"
                >
                    {{ $page.props.flash.success }}
                </div>

                <p
                    v-if="hasInProgress"
                    class="mb-4 flex items-center gap-2 text-sm text-indigo-700"
                >
                    <span class="relative flex h-3 w-3">
                        <span
                            class="absolute inline-flex h-full w-full animate-ping rounded-full bg-indigo-400 opacity-75"
                        ></span>
                        <span
                            class="relative inline-flex h-3 w-3 rounded-full bg-indigo-500"
                        ></span>
                    </span>
                    Some generations are still running — this page updates
                    automatically.
                </p>

                <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                    <div
                        v-if="!generations.data || generations.data.length === 0"
                        class="p-8 text-center text-gray-500"
                    >
                        No generations yet.
                        <Link
                            :href="route('generations.create')"
                            class="font-medium text-indigo-600 hover:text-indigo-500"
                        >
                            Generate your first image →
                        </Link>
                    </div>

                    <ul v-else class="divide-y divide-gray-200">
                        <li
                            v-for="g in generations.data"
                            :key="g.id"
                            class="flex gap-4 p-4 sm:items-center"
                        >
                            <div
                                class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-gray-100"
                            >
                                <img
                                    v-if="g.status === 'completed' && g.image_url"
                                    :src="g.image_url"
                                    :alt="g.prompt"
                                    class="h-full w-full object-cover"
                                />
                                <span v-else class="px-1 text-center text-[10px] text-gray-400">
                                    {{ statusLabel(g.status) }}
                                </span>
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-gray-900">
                                    {{ g.prompt }}
                                </p>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    {{ g.size }} · {{ g.quality }} ·
                                    {{ formatDate(g.created_at) }}
                                </p>
                                <p
                                    v-if="g.status === 'failed' && g.error_message"
                                    class="mt-1 text-sm text-red-600"
                                >
                                    {{ g.error_message }}
                                </p>
                            </div>

                            <div class="shrink-0 text-right">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="badgeClass(g.status)"
                                >
                                    {{ statusLabel(g.status) }}
                                </span>
                                <p class="mt-1 text-sm text-gray-700">
                                    <span class="text-gray-400">Est.</span>
                                    {{ money(g.estimated_price) }}
                                    <template v-if="g.status === 'completed'">
                                        · <span class="text-gray-400">Paid</span>
                                        <span class="font-semibold">
                                            {{ money(g.actual_price) }}
                                        </span>
                                    </template>
                                    <template v-else-if="g.status === 'failed'">
                                        · <span class="font-semibold">Paid $0.0000</span>
                                    </template>
                                </p>
                            </div>
                        </li>
                    </ul>

                    <!-- Pagination -->
                    <div
                        v-if="generations.links && generations.links.length > 3"
                        class="flex flex-wrap gap-1 border-t border-gray-200 px-4 py-3"
                    >
                        <template v-for="link in generations.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                class="rounded px-3 py-1 text-sm"
                                :class="
                                    link.active
                                        ? 'bg-indigo-600 text-white'
                                        : 'text-gray-600 hover:bg-gray-100'
                                "
                                v-html="link.label"
                            />
                            <span
                                v-else
                                class="px-3 py-1 text-sm text-gray-300"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
