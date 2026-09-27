<script setup>
const props = defineProps({
    modelValue: { type: Number, required: true },
    label: { type: String, required: true },
    min: { type: Number, default: 0 },
});
const emit = defineEmits(['update:modelValue']);

function change(delta) {
    emit('update:modelValue', Math.max(props.min, props.modelValue + delta));
}
function typed(event) {
    const value = parseInt(event.target.value, 10);
    emit('update:modelValue', Number.isNaN(value) ? props.min : Math.max(props.min, value));
}
</script>

<template>
    <div class="flex items-center gap-1" role="group" :aria-label="`${label} 數量`">
        <button type="button" class="h-14 w-14 rounded-lg border-2 border-cu-line bg-white text-3xl leading-none active:bg-slate-100 disabled:opacity-30"
            :disabled="modelValue <= min" :aria-label="`${label} 減一`" @click="change(-1)">−</button>
        <input :value="modelValue" type="number" inputmode="numeric" min="0" class="cu-num h-14 w-16 rounded-lg border-2 border-cu-line text-center text-xl font-bold"
            :aria-label="`${label} 數量`" @change="typed" @focus="$event.target.select()" />
        <button type="button" class="h-14 w-14 rounded-lg border-2 border-cu-pipe bg-white text-3xl leading-none text-cu-pipe active:bg-slate-100"
            :aria-label="`${label} 加一`" @click="change(1)">+</button>
    </div>
</template>
