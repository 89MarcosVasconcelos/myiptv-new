<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-zinc-950 px-4 py-10 text-zinc-100">
        <div class="mb-6 flex items-center gap-2">
            <span class="flex h-10 w-10 items-center justify-center rounded-md bg-gradient-to-br from-violet-500 to-fuchsia-600 font-bold text-white">M</span>
            <Link href="/" class="text-2xl font-semibold tracking-tight text-white">
                MP<span class="text-violet-400">IPTV</span>
            </Link>
        </div>

        <div class="w-full overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900 px-6 py-6 shadow-xl sm:max-w-md">
            <slot />
        </div>
    </div>
</template>
