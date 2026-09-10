<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="w-full rounded-md border-zinc-700 bg-zinc-800 text-zinc-100 placeholder-zinc-500 shadow-sm focus:border-violet-500 focus:ring-violet-500"
        v-model="model"
        ref="input"
    />
</template>
