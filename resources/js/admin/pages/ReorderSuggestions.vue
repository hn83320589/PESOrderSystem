<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../../shared/http';
import { purchaseDraft } from '../purchaseDraft';

const router = useRouter();
const suggestions = ref(null);
const selected = ref([]);
const quantities = ref({});

onMounted(async () => {
    suggestions.value = (await api('GET', '/api/admin/reorder-suggestions')).data;
    suggestions.value.forEach((s) => (quantities.value[s.variant_id] = s.suggested_quantity));
});

// 依最近一次的供應商分組，一組轉成一張進貨單
const groups = computed(() => {
    const map = new Map();
    for (const s of suggestions.value ?? []) {
        const key = s.last_supplier_id ?? 0;
        if (!map.has(key)) map.set(key, { supplier_id: s.last_supplier_id, name: s.last_supplier_name ?? '尚無進貨紀錄', rows: [] });
        map.get(key).rows.push(s);
    }
    return [...map.values()];
});

function toPurchase(group) {
    const rows = group.rows.filter((r) => selected.value.includes(r.variant_id));
    purchaseDraft.value = {
        supplier_id: group.supplier_id,
        items: rows.map((r) => ({ variant_id: r.variant_id, quantity: quantities.value[r.variant_id], unit_cost: r.last_unit_cost ?? r.avg_cost })),
    };
    router.push('/purchases/new');
}
const selectedIn = (group) => group.rows.filter((r) => selected.value.includes(r.variant_id)).length;
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <RouterLink to="/purchases" class="btn">← 進貨</RouterLink>
            <h1 class="text-xl font-bold">叫貨建議</h1>
        </div>
        <p class="text-sm text-gray-600">目標量＝近 30 天出貨量（至少 10）；建議數量＝目標量 − 可用量 − 已下單未到貨。只是建議，勾選後帶入進貨單，確認送出才會成立。</p>
        <p v-if="suggestions && !suggestions.length" class="rounded bg-white p-6 text-center text-gray-500 shadow">目前庫存都足夠，沒有需要叫的貨。</p>

        <section v-for="group in groups" :key="group.supplier_id ?? 0" class="rounded bg-white shadow">
            <div class="flex flex-wrap items-center gap-2 border-b px-4 py-2">
                <h2 class="font-bold">{{ group.name }}</h2>
                <span class="text-sm text-gray-500">{{ group.rows.length }} 項</span>
                <button class="btn btn-primary ml-auto" :disabled="!selectedIn(group)" @click="toPurchase(group)">
                    勾選的 {{ selectedIn(group) }} 項帶入進貨單
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="table whitespace-nowrap">
                    <thead><tr><th></th><th>品項</th><th class="text-right">可用</th><th class="text-right">在途</th><th class="text-right">近 30 天出貨</th><th class="w-28">叫貨數量</th><th class="text-right">上次進價</th></tr></thead>
                    <tbody>
                        <tr v-for="s in group.rows" :key="s.variant_id">
                            <td><input v-model="selected" type="checkbox" :value="s.variant_id" :aria-label="`選擇 ${s.product_name} ${s.spec}`" /></td>
                            <td>{{ s.product_name }} {{ s.spec }}</td>
                            <td class="text-right tabular-nums" :class="{ 'font-bold text-red-600': s.available <= 0 }">{{ s.available }}</td>
                            <td class="text-right tabular-nums">{{ s.on_order || '' }}</td>
                            <td class="text-right tabular-nums">{{ s.shipped_30_days }}</td>
                            <td><input v-model.number="quantities[s.variant_id]" type="number" min="1" class="input" /></td>
                            <td class="text-right tabular-nums">{{ s.last_unit_cost != null ? `$${s.last_unit_cost}` : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
