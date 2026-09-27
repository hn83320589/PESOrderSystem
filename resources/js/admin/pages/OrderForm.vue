<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../../shared/http';
import { date, errorMessage, money } from '../../shared/format';

const route = useRoute();
const router = useRouter();
const orderId = route.params.id ?? null;
const isEdit = orderId !== null;

const customers = ref([]);
const variants = ref([]);
const form = ref({ customer_id: null, payment_method: 'bank_transfer', note: '' });
const lines = ref([]); // { variant_id, label, unit, unit_price, quantity, warning }
const error = ref('');
const submitting = ref(false);
const orderNo = ref('');

const variantById = computed(() => Object.fromEntries(variants.value.map((v) => [v.id, v])));
const total = computed(() => lines.value.reduce((sum, l) => sum + l.unit_price * (l.quantity || 0), 0));

onMounted(async () => {
    [customers.value, variants.value] = await Promise.all([
        api('GET', '/api/admin/customers').then((r) => r.data.filter((c) => c.is_active)),
        api('GET', '/api/admin/inventory').then((r) => r.data),
    ]);
    if (isEdit) {
        const { data } = await api('GET', `/api/admin/orders/${orderId}`);
        orderNo.value = data.order_no;
        form.value = { customer_id: data.customer.id, payment_method: data.payment_method, note: data.note ?? '' };
        lines.value = data.items.map((i) => ({
            variant_id: i.variant_id,
            label: `${i.product_name} ${i.spec}`,
            unit: i.unit,
            unit_price: i.unit_price,
            quantity: i.quantity,
            original: i.quantity,
        }));
    } else if (route.query.customer_id) {
        form.value.customer_id = Number(route.query.customer_id);
    }
});

// 選客戶：依結帳方式預設付款方式，並載入老樣子
const recentOrders = ref([]);
watch(() => form.value.customer_id, async (id) => {
    recentOrders.value = [];
    if (!id) return;
    const customer = customers.value.find((c) => c.id === id);
    if (!isEdit && customer) {
        form.value.payment_method = customer.billing_type === 'monthly' ? 'bank_transfer' : 'cash_on_delivery';
    }
    if (!isEdit) {
        recentOrders.value = (await api('GET', `/api/admin/customers/${id}/recent-orders`)).data;
    }
});

// 加入品項
const picker = ref({ keyword: '', variant_id: null });
const pickerOptions = computed(() => {
    const k = picker.value.keyword.trim();
    return variants.value.filter((v) => v.is_active && (!k || `${v.product_name} ${v.spec} ${v.sku ?? ''}`.includes(k)));
});
function addLine(variantId, quantity = 1, warning = '') {
    const v = variantById.value[variantId];
    const existing = lines.value.find((l) => l.variant_id === variantId);
    if (existing) {
        existing.quantity += quantity;
        return;
    }
    lines.value.push({ variant_id: v.id, label: `${v.product_name} ${v.spec}`, unit: v.unit, unit_price: v.price, quantity, warning });
}
function addPicked() {
    if (picker.value.variant_id) {
        addLine(picker.value.variant_id);
        picker.value.variant_id = null;
    }
}

// 老樣子：帶入某筆歷史訂單，標示價格變動、停售、庫存不足
function reorder(order) {
    lines.value = [];
    const skipped = [];
    for (const item of order.items) {
        if (!item.current?.orderable || !variantById.value[item.variant_id]) {
            skipped.push(`${item.product_name} ${item.spec}`);
            continue;
        }
        const notes = [];
        if (item.current.price !== item.unit_price) notes.push(`單價已由 ${money(item.unit_price)} 調整為 ${money(item.current.price)}`);
        if (item.current.available < item.quantity) notes.push(`目前可用僅 ${item.current.available}`);
        addLine(item.variant_id, item.quantity, notes.join('；'));
    }
    error.value = skipped.length ? `以下品項已停售，未帶入：${skipped.join('、')}` : '';
}

const availableFor = (line) => {
    const v = variantById.value[line.variant_id];
    // 改單時，原本這張單已佔用的量也可以使用
    return v ? v.stock.available + (line.original ?? 0) : null;
};

async function submit() {
    submitting.value = true;
    error.value = '';
    const body = {
        payment_method: form.value.payment_method,
        note: form.value.note,
        items: lines.value.map((l) => ({ variant_id: l.variant_id, quantity: l.quantity })),
    };
    try {
        const { data } = isEdit
            ? await api('PATCH', `/api/admin/orders/${orderId}`, body)
            : await api('POST', '/api/admin/orders', { ...body, customer_id: form.value.customer_id });
        router.push(`/orders/${data.id}`);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-3">
        <form class="space-y-4 rounded bg-white p-4 shadow lg:col-span-2" @submit.prevent="submit">
            <h1 class="text-xl font-bold">{{ isEdit ? `修改訂單 ${orderNo}` : '代客下單' }}</h1>
            <p v-if="error" class="alert-error">{{ error }}</p>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="label">客戶</label>
                    <select v-model="form.customer_id" class="input" :disabled="isEdit" required>
                        <option :value="null" disabled>請選擇客戶</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}（{{ c.billing_type_label }}）</option>
                    </select>
                </div>
                <div>
                    <label class="label">付款方式</label>
                    <select v-model="form.payment_method" class="input">
                        <option value="bank_transfer">匯款</option>
                        <option value="cash_on_delivery">現金貨到付款</option>
                        <option value="check">支票</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="label">加入商品</label>
                <div class="flex flex-wrap gap-2">
                    <input v-model="picker.keyword" class="input w-40" placeholder="篩選品名/規格" />
                    <select v-model="picker.variant_id" class="input flex-1" @change="addPicked">
                        <option :value="null">選擇商品規格（選了就加入）</option>
                        <option v-for="v in pickerOptions" :key="v.id" :value="v.id">
                            {{ v.product_name }} {{ v.spec }}｜{{ money(v.price) }}｜可用 {{ v.stock.available }}
                        </option>
                    </select>
                </div>
            </div>

            <table class="table">
                <thead>
                    <tr><th>品項</th><th class="text-right">單價</th><th class="w-28">數量</th><th class="text-right">小計</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="(line, i) in lines" :key="line.variant_id">
                        <td>
                            {{ line.label }}
                            <div v-if="line.warning" class="text-xs text-amber-700">⚠ {{ line.warning }}</div>
                            <div v-if="availableFor(line) !== null && line.quantity > availableFor(line)" class="text-xs text-red-600">
                                超過可用量 {{ availableFor(line) }}
                            </div>
                        </td>
                        <td class="text-right">{{ money(line.unit_price) }}</td>
                        <td><input v-model.number="line.quantity" type="number" min="1" class="input" required /></td>
                        <td class="text-right">{{ money(line.unit_price * (line.quantity || 0)) }}</td>
                        <td><button type="button" class="btn btn-danger" @click="lines.splice(i, 1)">刪</button></td>
                    </tr>
                    <tr v-if="!lines.length"><td colspan="5" class="py-6 text-center text-gray-400">尚未加入商品</td></tr>
                </tbody>
                <tfoot>
                    <tr><td colspan="3" class="text-right font-bold">合計</td><td class="text-right text-lg font-bold">{{ money(total) }}</td><td></td></tr>
                </tfoot>
            </table>

            <div><label class="label">備註</label><textarea v-model="form.note" class="input" rows="2" placeholder="例：下午送、放後門"></textarea></div>
            <div class="flex gap-2">
                <button class="btn btn-primary px-6 py-2" :disabled="submitting || !lines.length || !form.customer_id">
                    {{ submitting ? '送出中…' : isEdit ? '儲存修改' : '建立訂單' }}
                </button>
                <button type="button" class="btn" @click="router.back()">取消</button>
            </div>
        </form>

        <aside v-if="!isEdit" class="space-y-2 rounded bg-white p-4 shadow">
            <h2 class="font-bold">老樣子（最近 10 筆）</h2>
            <p v-if="!form.customer_id" class="text-sm text-gray-400">先選客戶</p>
            <p v-else-if="!recentOrders.length" class="text-sm text-gray-400">這位客戶還沒有訂單</p>
            <div v-for="o in recentOrders" :key="o.id" class="rounded border p-2 text-sm">
                <div class="flex items-center justify-between">
                    <span>{{ date(o.created_at) }}　{{ money(o.total_amount) }}</span>
                    <button type="button" class="btn" @click="reorder(o)">帶入</button>
                </div>
                <div class="mt-1 text-gray-600">{{ o.items.map((i) => `${i.product_name} ${i.spec}×${i.quantity}`).join('、') }}</div>
            </div>
        </aside>
    </div>
</template>
