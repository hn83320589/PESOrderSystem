<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '../../shared/http';
import { date, dateTime, errorMessage, money } from '../../shared/format';
import MarkPaidForm from '../components/MarkPaidForm.vue';
import Modal from '../components/Modal.vue';

const route = useRoute();
const filters = ref({ status: 'unpaid', customer_id: route.query.customer_id ? Number(route.query.customer_id) : '' });
const payments = ref([]);
const customers = ref([]);
const selected = ref([]);
const error = ref('');

async function load() {
    const params = new URLSearchParams(Object.entries(filters.value).filter(([, v]) => v));
    payments.value = (await api('GET', `/api/admin/payments?${params}`)).data;
    selected.value = [];
}
onMounted(async () => {
    customers.value = (await api('GET', '/api/admin/customers')).data;
    await load();
});

const selectedPayments = computed(() => payments.value.filter((p) => selected.value.includes(p.id)));
const selectedAmount = computed(() => selectedPayments.value.reduce((sum, p) => sum + p.amount, 0));
const unpaidTotal = computed(() => payments.value.filter((p) => p.status === 'unpaid').reduce((s, p) => s + p.amount, 0));

// 單筆或批次標記收款
const marking = ref(null);
function openMark(list) {
    error.value = '';
    marking.value = list;
}
async function submitMark(body) {
    try {
        if (marking.value.length === 1) {
            await api('POST', `/api/admin/payments/${marking.value[0].id}/mark-paid`, body);
        } else {
            await api('POST', '/api/admin/payments/bulk-mark-paid', { ...body, payment_ids: marking.value.map((p) => p.id) });
        }
        marking.value = null;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function markUnpaid(payment) {
    if (!confirm(`確定將訂單 ${payment.order.order_no} 改回「未收」？`)) return;
    try {
        await api('POST', `/api/admin/payments/${payment.id}/mark-unpaid`);
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="mr-2 text-xl font-bold">收款</h1>
            <select v-model="filters.status" class="input w-28" @change="load">
                <option value="unpaid">未收</option>
                <option value="paid">已收</option>
                <option value="">全部</option>
            </select>
            <select v-model="filters.customer_id" class="input w-48" @change="load">
                <option value="">全部客戶</option>
                <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <button v-if="selected.length" class="btn btn-primary" @click="openMark(selectedPayments)">
                勾選的 {{ selected.length }} 筆標記收款（{{ money(selectedAmount) }}）
            </button>
            <span v-if="filters.status === 'unpaid'" class="ml-auto text-sm">列表未收合計 <strong>{{ money(unpaidTotal) }}</strong></span>
        </div>
        <p v-if="error && !marking" class="alert-error">{{ error }}</p>

        <div class="overflow-x-auto rounded shadow">
            <table class="table">
                <thead>
                    <tr><th></th><th>單號</th><th>客戶</th><th>訂單狀態</th><th class="text-right">金額</th><th>方式</th><th>狀態</th><th>收款資訊</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="p in payments" :key="p.id">
                        <td><input v-if="p.status === 'unpaid'" v-model="selected" type="checkbox" :value="p.id" /></td>
                        <td><RouterLink class="font-mono text-blue-700 hover:underline" :to="`/orders/${p.order.id}`">{{ p.order.order_no }}</RouterLink></td>
                        <td>{{ p.customer.name }}</td>
                        <td>{{ p.order.status_label }}<span v-if="p.order.shipped_at" class="text-xs text-gray-400"> {{ date(p.order.shipped_at) }}</span></td>
                        <td class="text-right">{{ money(p.amount) }}</td>
                        <td>{{ p.method_label }}</td>
                        <td :class="p.status === 'paid' ? 'text-green-700' : 'text-red-600'">{{ p.status_label }}</td>
                        <td class="text-xs">
                            <template v-if="p.paid_at">{{ dateTime(p.paid_at) }}</template>
                            <template v-if="p.check_no"><br />票號 {{ p.check_no }}，兌現 {{ p.check_due_date }}</template>
                            <template v-if="p.note"><br />{{ p.note }}</template>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <button v-if="p.status === 'unpaid'" class="btn" @click="openMark([p])">收款</button>
                            <button v-else class="btn btn-danger" @click="markUnpaid(p)">改回未收</button>
                        </td>
                    </tr>
                    <tr v-if="!payments.length"><td colspan="9" class="py-8 text-center text-gray-400">沒有資料</td></tr>
                </tbody>
            </table>
        </div>

        <Modal v-if="marking" title="標記收款" @close="marking = null">
            <MarkPaidForm :count="marking.length" :amount="marking.reduce((s, p) => s + p.amount, 0)" :default-method="marking[0].method" :error="error" @submit="submitMark" />
        </Modal>
    </div>
</template>
