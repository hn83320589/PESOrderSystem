<script setup>
import { onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../../shared/http';
import { dateTime, money } from '../../shared/format';

const route = useRoute();
const router = useRouter();
const statuses = [
    { value: '', label: '全部狀態' },
    { value: 'pending', label: '待確認' },
    { value: 'confirmed', label: '已確認' },
    { value: 'shipped', label: '已出貨' },
    { value: 'expired', label: '已失效' },
];
const statusClass = { pending: 'bg-amber-100 text-amber-800', confirmed: 'bg-blue-100 text-blue-800', shipped: 'bg-green-100 text-green-800', expired: 'bg-gray-200 text-gray-600' };

const filters = ref({ status: '', customer_id: '', q: '', date_from: '', date_to: '', page: 1 });
const orders = ref([]);
const meta = ref(null);
const customers = ref([]);

async function load() {
    const params = new URLSearchParams(Object.entries(filters.value).filter(([, v]) => v !== '' && v !== null));
    const response = await api('GET', `/api/admin/orders?${params}`);
    orders.value = response.data;
    meta.value = response.meta;
}

function search() {
    filters.value.page = 1;
    router.replace({ query: Object.fromEntries(Object.entries(filters.value).filter(([k, v]) => v && k !== 'page')) });
}

watch(() => route.query, (query) => {
    filters.value = { status: '', customer_id: '', q: '', date_from: '', date_to: '', page: 1, ...query };
    load();
}, { immediate: true });

onMounted(async () => {
    customers.value = (await api('GET', '/api/admin/customers')).data;
});

function goPage(page) {
    filters.value.page = page;
    load();
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="mr-2 text-xl font-bold">訂單</h1>
            <RouterLink class="btn btn-primary" to="/orders/new">＋ 代客下單</RouterLink>
        </div>
        <form class="flex flex-wrap items-end gap-2 rounded bg-white p-3 shadow" @submit.prevent="search">
            <select v-model="filters.status" class="input w-32">
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
            <select v-model="filters.customer_id" class="input w-48">
                <option value="">全部客戶</option>
                <option v-for="c in customers" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
            <input v-model="filters.q" class="input w-40" placeholder="單號" />
            <input v-model="filters.date_from" type="date" class="input w-40" />
            <span class="pb-2">～</span>
            <input v-model="filters.date_to" type="date" class="input w-40" />
            <button class="btn">查詢</button>
        </form>

        <div class="overflow-x-auto rounded shadow">
            <table class="table">
                <thead>
                    <tr><th>單號</th><th>下單時間</th><th>客戶</th><th>狀態</th><th class="text-right">金額</th><th>付款</th><th>來源</th></tr>
                </thead>
                <tbody>
                    <tr v-for="o in orders" :key="o.id" class="cursor-pointer hover:bg-blue-50" @click="router.push(`/orders/${o.id}`)">
                        <td class="font-mono">{{ o.order_no }}</td>
                        <td class="whitespace-nowrap">{{ dateTime(o.created_at) }}</td>
                        <td>{{ o.customer.name }}</td>
                        <td><span class="rounded px-2 py-0.5 text-xs" :class="statusClass[o.status]">{{ o.status_label }}</span></td>
                        <td class="text-right">{{ money(o.total_amount) }}</td>
                        <td>{{ o.payment_method_label }}／{{ o.payment?.status_label }}</td>
                        <td>{{ o.source === 'customer' ? '客戶自行下單' : '代客下單' }}</td>
                    </tr>
                    <tr v-if="!orders.length"><td colspan="7" class="py-8 text-center text-gray-400">沒有訂單</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="meta && meta.last_page > 1" class="flex items-center justify-center gap-2">
            <button class="btn" :disabled="meta.current_page <= 1" @click="goPage(meta.current_page - 1)">上一頁</button>
            <span class="text-sm">第 {{ meta.current_page }} / {{ meta.last_page }} 頁，共 {{ meta.total }} 筆</span>
            <button class="btn" :disabled="meta.current_page >= meta.last_page" @click="goPage(meta.current_page + 1)">下一頁</button>
        </div>
    </div>
</template>
