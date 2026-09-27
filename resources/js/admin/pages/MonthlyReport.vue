<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../../shared/http';
import { date, money } from '../../shared/format';
import Modal from '../components/Modal.vue';

const now = new Date();
const month = ref(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`);
const billingType = ref('');
const report = ref(null);

const query = () => new URLSearchParams(Object.entries({ month: month.value, billing_type: billingType.value }).filter(([, v]) => v));

async function load() {
    report.value = (await api('GET', `/api/admin/reports/monthly?${query()}`)).data;
}
onMounted(load);

const exportUrl = () => `/api/admin/reports/monthly/export?${query()}`;

const detail = ref(null);
async function openDetail(row) {
    detail.value = (await api('GET', `/api/admin/reports/monthly/${row.customer_id}?month=${month.value}`)).data;
}

const columns = [
    ['order_count', '出貨單數', false],
    ['shipped_total', '本期出貨', true],
    ['paid_total', '本期已收', true],
    ['unpaid_total', '本期未收', true],
    ['previous_unpaid', '前期未收', true],
    ['outstanding', '累計應收', true],
];
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="mr-2 text-xl font-bold">月結對帳</h1>
            <input v-model="month" type="month" class="input w-44" @change="load" />
            <select v-model="billingType" class="input w-32" @change="load">
                <option value="">全部客戶</option>
                <option value="monthly">月結客戶</option>
                <option value="cash_on_delivery">貨到付款</option>
            </select>
            <a class="btn btn-primary" :href="exportUrl()">下載 Excel</a>
        </div>
        <p class="text-sm text-gray-500">以「出貨日」歸屬月份，只計入已出貨訂單。前期未收＝本月以前出貨、至今未收款的金額。</p>

        <div v-if="report" class="overflow-x-auto rounded shadow">
            <table class="table">
                <thead>
                    <tr><th>客戶</th><th>結帳方式</th><th v-for="[key, label] in columns" :key="key" class="text-right">{{ label }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="row in report.rows" :key="row.customer_id" class="cursor-pointer hover:bg-blue-50" @click="openDetail(row)">
                        <td class="font-medium text-blue-700">{{ row.customer_name }}</td>
                        <td>{{ row.billing_type_label }}</td>
                        <td v-for="[key, , isMoney] in columns" :key="key" class="text-right"
                            :class="{ 'font-bold text-red-600': key === 'outstanding' && row[key] > 0 }">
                            {{ isMoney ? money(row[key]) : row[key] }}
                        </td>
                    </tr>
                    <tr v-if="!report.rows.length"><td colspan="8" class="py-8 text-center text-gray-400">本月沒有出貨紀錄</td></tr>
                </tbody>
                <tfoot v-if="report.rows.length">
                    <tr class="font-bold">
                        <td colspan="2">合計</td>
                        <td v-for="[key, , isMoney] in columns" :key="key" class="text-right">{{ isMoney ? money(report.totals[key]) : report.totals[key] }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <Modal v-if="detail" :title="`${detail.customer.name}｜${month} 出貨明細`" @close="detail = null">
            <div class="max-h-[65vh] space-y-3 overflow-y-auto">
                <p v-if="detail.summary" class="text-sm">
                    本期出貨 {{ money(detail.summary.shipped_total) }}，已收 {{ money(detail.summary.paid_total) }}，
                    未收 {{ money(detail.summary.unpaid_total) }}，前期未收 {{ money(detail.summary.previous_unpaid) }}
                </p>
                <div v-for="o in detail.orders" :key="o.id" class="rounded border p-2 text-sm">
                    <div class="flex justify-between font-medium">
                        <RouterLink class="text-blue-700 hover:underline" :to="`/orders/${o.id}`">{{ o.order_no }}</RouterLink>
                        <span>出貨 {{ date(o.shipped_at) }}｜{{ money(o.total_amount) }}｜{{ o.payment?.status_label }}</span>
                    </div>
                    <ul class="mt-1 text-gray-600">
                        <li v-for="i in o.items" :key="i.id">{{ i.product_name }} {{ i.spec }} × {{ i.quantity }}{{ i.unit }} ＝ {{ money(i.subtotal) }}</li>
                    </ul>
                </div>
            </div>
        </Modal>
    </div>
</template>
