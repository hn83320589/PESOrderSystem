<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../../shared/http';
import { date, errorMessage } from '../../shared/format';

const statuses = [
    { value: 'ordered', label: '已下單未到貨' },
    { value: 'received', label: '已到貨入庫' },
    { value: 'cancelled', label: '已取消' },
    { value: '', label: '全部' },
];
const status = ref('ordered');
const orders = ref([]);
const expanded = ref(null);
const error = ref('');
const busy = ref(null);

async function load() {
    orders.value = (await api('GET', `/api/admin/purchase-orders${status.value ? `?status=${status.value}` : ''}`)).data;
}
onMounted(load);

const cost = (v) => `$${Number(v).toLocaleString('zh-TW', { maximumFractionDigits: 2 })}`;

async function act(po, action, confirmText) {
    if (!confirm(confirmText)) return;
    busy.value = po.id;
    error.value = '';
    try {
        await api('POST', `/api/admin/purchase-orders/${po.id}/${action}`);
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="mr-2 text-xl font-bold">進貨</h1>
            <RouterLink to="/purchases/new" class="btn btn-primary">＋ 建立進貨單</RouterLink>
            <RouterLink to="/purchases/suggestions" class="btn">叫貨建議</RouterLink>
            <RouterLink to="/suppliers" class="btn">供應商</RouterLink>
            <select v-model="status" class="input ml-auto w-36" @change="load">
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
        </div>
        <p v-if="error" class="alert-error">{{ error }}</p>

        <div class="overflow-x-auto rounded shadow">
            <table class="table">
                <thead><tr><th>單號</th><th>日期</th><th>供應商</th><th>品項</th><th class="text-right">金額</th><th>狀態</th><th></th></tr></thead>
                <tbody>
                    <template v-for="po in orders" :key="po.id">
                        <tr class="cursor-pointer hover:bg-blue-50" @click="expanded = expanded === po.id ? null : po.id">
                            <td class="font-mono">{{ po.po_no }}</td>
                            <td class="whitespace-nowrap">{{ date(po.created_at) }}</td>
                            <td>{{ po.supplier.name }}</td>
                            <td class="max-w-72 truncate">{{ po.items.map((i) => `${i.product_name} ${i.spec}×${i.quantity}`).join('、') }}</td>
                            <td class="text-right tabular-nums">{{ cost(po.total_cost) }}</td>
                            <td>{{ po.status_label }}<span v-if="po.received_at" class="text-xs text-gray-400"> {{ date(po.received_at) }}</span></td>
                            <td class="space-x-1 text-right whitespace-nowrap" @click.stop>
                                <template v-if="po.status === 'ordered'">
                                    <button class="btn btn-primary" :disabled="busy === po.id" @click="act(po, 'receive', `確認 ${po.po_no} 已到貨？庫存將增加。`)">到貨入庫</button>
                                    <button class="btn btn-danger" :disabled="busy === po.id" @click="act(po, 'cancel', `確定取消 ${po.po_no}？`)">取消</button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="expanded === po.id">
                            <td colspan="7" class="bg-gray-50">
                                <table class="w-full text-sm">
                                    <tr v-for="i in po.items" :key="i.id">
                                        <td class="py-1">{{ i.product_name }} {{ i.spec }}</td>
                                        <td class="text-right tabular-nums">{{ i.quantity }} {{ i.unit }}</td>
                                        <td class="text-right tabular-nums">× {{ cost(i.unit_cost) }}</td>
                                        <td class="text-right tabular-nums">{{ cost(i.subtotal) }}</td>
                                    </tr>
                                </table>
                                <p v-if="po.note" class="mt-1 text-sm text-gray-600">備註：{{ po.note }}</p>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!orders.length"><td colspan="7" class="py-8 text-center text-gray-400">沒有進貨單</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
