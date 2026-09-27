<script setup>
defineProps({ title: { type: String, required: true } });
defineEmits(['close']);
</script>

<template>
    <div class="fixed inset-0 z-10 flex items-start justify-center overflow-y-auto bg-black/40 p-4" @click.self="$emit('close')">
        <div class="mt-12 w-full max-w-lg rounded-lg bg-white shadow-xl">
            <div class="flex items-center justify-between border-b px-4 py-3">
                <h2 class="font-bold">{{ title }}</h2>
                <button class="text-2xl leading-none text-gray-400 hover:text-gray-700" aria-label="關閉" @click="$emit('close')">×</button>
            </div>
            <div class="p-4">
                <slot />
            </div>
        </div>
    </div>
</template>
