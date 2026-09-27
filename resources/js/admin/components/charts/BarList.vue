<script setup>
// 橫條圖：每列一個類別，數值標在條尾；顏色可逐列指定（例如帳齡的順序色階），預設單一色。
import { computed, ref } from 'vue';

const props = defineProps({
    title: { type: String, required: true },
    rows: { type: Array, required: true }, // [{ label, value, color?, note? }]
    color: { type: String, default: '#2a78d6' },
    format: { type: Function, default: (v) => v.toLocaleString('zh-TW') },
    emptyText: { type: String, default: '沒有資料' },
});

const max = computed(() => Math.max(1, ...props.rows.map((r) => r.value)));
const showTable = ref(false);
const active = ref(null);
</script>

<template>
    <figure class="rounded bg-white p-4 shadow">
        <div class="mb-3 flex items-center">
            <figcaption class="font-bold">{{ title }}</figcaption>
            <button class="ml-auto text-sm text-blue-700 hover:underline" :aria-pressed="showTable" @click="showTable = !showTable">
                {{ showTable ? '看圖表' : '表格檢視' }}
            </button>
        </div>
        <p v-if="!rows.length" class="py-6 text-center text-gray-400">{{ emptyText }}</p>

        <ul v-else-if="!showTable" class="space-y-2">
            <li v-for="(row, i) in rows" :key="row.label" tabindex="0" class="relative grid grid-cols-[7rem_1fr] items-center gap-3 rounded outline-none focus-visible:ring-2 focus-visible:ring-blue-400"
                :aria-label="`${row.label}：${format(row.value)}${row.note ? `，${row.note}` : ''}`"
                @pointerenter="active = i" @pointerleave="active = null" @focus="active = i" @blur="active = null">
                <span class="truncate text-sm text-gray-700" :title="row.label">{{ row.label }}</span>
                <span class="flex items-center gap-2">
                    <span class="h-4 rounded-r" :style="{ width: `${Math.max(row.value > 0 ? 0.5 : 0, (row.value / max) * 85)}%`, background: row.color ?? color, opacity: active === null || active === i ? 1 : 0.55 }"></span>
                    <span class="text-sm whitespace-nowrap text-gray-900 tabular-nums">{{ format(row.value) }}</span>
                    <span v-if="row.note" class="text-xs whitespace-nowrap text-gray-500">{{ row.note }}</span>
                </span>
            </li>
        </ul>

        <table v-else class="table">
            <tbody>
                <tr v-for="row in rows" :key="row.label">
                    <td>{{ row.label }}</td>
                    <td class="text-right tabular-nums">{{ format(row.value) }}</td>
                    <td class="text-gray-500">{{ row.note }}</td>
                </tr>
            </tbody>
        </table>
    </figure>
</template>
