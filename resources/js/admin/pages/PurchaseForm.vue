<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../../shared/http';
import { errorMessage } from '../../shared/format';
import { purchaseDraft } from '../purchaseDraft';

const router = useRouter();
const suppliers = ref([]);
const variants = ref([]);
const form = ref({ supplier_id: null, note: '', receive_now: false });
const lines = ref([]); // { variant_id, label, unit, quantity, unit_cost }
const picker = ref({ keyword: '', variant_id: null });
const error = ref('');
const submitting = ref(false);

const variantById = computed(() => Object.fromEntries(variants.value.map((v) => [v.id, v])));
const total = computed(() => lines.value.reduce((sum, l) => sum + (Number(l.unit_cost) || 0) * (l.quantity || 0), 0));
const pickerOptions = computed(() => {
    const k = picker.value.keyword.trim();
    return variants.value.filter((v) => !k || `${v.product_name} ${v.spec} ${v.sku ?? ''}`.includes(k));
});
const cost = (v) => `$${Number(v).toLocaleString('zh-TW', { maximumFractionDigits: 2 })}`;

function addLine(variantId, quantity = 1, unitCost = null) {
    const v = variantById.value[variantId];
    if (!v || lines.value.some((l) => l.variant_id === variantId)) return;
    lines.value.push({ variant_id: v.id, label: `${v.product_name} ${v.spec}`, unit: v.unit, quantity, unit_cost: unitCost ?? v.avg_cost ?? '' });
}

onMounted(async () => {
    [suppliers.value, variants.value] = await Promise.all([
        api('GET', '/api/admin/suppliers').then((r) => r.data.filter((s) => s.is_active)),
        api('GET', '/api/admin/inventory').then((r) => r.data),
    ]);
    // 從叫貨建議帶入
    if (purchaseDraft.value) {
        form.value.supplier_id = purchaseDraft.value.supplier_id;
        purchaseDraft.value.items.forEach((i) => addLine(i.variant_id, i.quantity, i.unit_cost));
        purchaseDraft.value = null;
    }
});

function addPicked() {
    if (picker.value.variant_id) {
        addLine(picker.value.variant_id);
        picker.value.variant_id = null;
    }
}

async function submit() {
    if (form.value.receive_now && !confirm('當場到貨會立即增加庫存，確定？')) return;
    submitting.value = true;
    error.value = '';
    try {
        await api('POST', '/api/admin/purchase-orders', {
            ...form.value,
            items: lines.value.map((l) => ({ variant_id: l.variant_id, quantity: l.quantity, unit_cost: l.unit_cost })),
        });
        router.push('/purchases');
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <form class="space-y-4 rounded bg-white p-4 shadow" @submit.prevent="submit">
        <div class="flex items-center gap-3">
            <RouterLink to="/purchases" class="btn">← 進貨</RouterLink>
            <h1 class="text-xl font-bold">建立進貨單</h1>
        </div>
        <p v-if="error" class="alert-error">{{ error }}</p>

        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="label">供應商</label>
                <select v-model="form.supplier_id" class="input" required>
                    <option :value="null" disabled>請選擇</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <p v-if="!suppliers.length" class="mt-1 text-sm">還沒有供應商，<RouterLink to="/suppliers" class="text-blue-700 underline">先新增</RouterLink></p>
            </div>
            <fieldset>
                <legend class="label">到貨方式</legend>
                <label class="mr-4"><input v-model="form.receive_now" type="radio" :value="false" /> 先下單，到貨再入庫</label>
                <label><input v-model="form.receive_now" type="radio" :value="true" /> 當場到貨，立即入庫</label>
            </fieldset>
        </div>

        <div>
            <label class="label">加入商品</label>
            <div class="flex flex-wrap gap-2">
                <input v-model="picker.keyword" class="input w-40" placeholder="篩選品名/規格" />
                <select v-model="picker.variant_id" class="input flex-1" @change="addPicked">
                    <option :value="null">選擇商品規格（選了就加入）</option>
                    <option v-for="v in pickerOptions" :key="v.id" :value="v.id">{{ v.product_name }} {{ v.spec }}｜可用 {{ v.stock.available }}</option>
                </select>
            </div>
        </div>

        <table class="table">
            <thead><tr><th>品項</th><th class="w-28">數量</th><th class="w-32">進價（元）</th><th class="text-right">小計</th><th></th></tr></thead>
            <tbody>
                <tr v-for="(line, i) in lines" :key="line.variant_id">
                    <td>{{ line.label }}</td>
                    <td><input v-model.number="line.quantity" type="number" min="1" class="input" required /></td>
                    <td><input v-model="line.unit_cost" type="number" min="0" step="0.01" class="input" required /></td>
                    <td class="text-right tabular-nums">{{ cost((Number(line.unit_cost) || 0) * (line.quantity || 0)) }}</td>
                    <td><button type="button" class="btn btn-danger" @click="lines.splice(i, 1)">刪</button></td>
                </tr>
                <tr v-if="!lines.length"><td colspan="5" class="py-6 text-center text-gray-400">尚未加入商品</td></tr>
            </tbody>
            <tfoot><tr><td colspan="3" class="text-right font-bold">合計</td><td class="text-right text-lg font-bold tabular-nums">{{ cost(total) }}</td><td></td></tr></tfoot>
        </table>
        <p class="text-sm text-gray-500">進價預設帶入目前平均成本或上次進價，可修改。到貨入庫時以移動加權平均更新成本。</p>

        <div><label class="label">備註</label><input v-model="form.note" class="input" placeholder="例：週三到貨、單號 A123" /></div>
        <button class="btn btn-primary px-6 py-2" :disabled="submitting || !lines.length || !form.supplier_id">
            {{ form.receive_now ? '建立並入庫' : '建立進貨單' }}
        </button>
    </form>
</template>
