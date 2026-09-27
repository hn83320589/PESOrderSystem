<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../../shared/http';
import { longDate, money, orderStatusText } from '../store';

const orders = ref(null);
const meta = ref(null);

async function load(page = 1) {
    const response = await api('GET', `/api/customer/orders?page=${page}`);
    orders.value = page === 1 ? response.data : [...orders.value, ...response.data];
    meta.value = response.meta;
}
onMounted(() => load());

const statusClass = { pending: 'bg-cu-caution', confirmed: 'bg-blue-100', shipped: 'bg-green-100', expired: 'bg-slate-200' };
</script>

<template>
    <div class="px-4 pt-4">
        <p v-if="orders && !orders.length" class="cu-panel p-5 text-lg">還沒有訂單。</p>
        <ul class="cu-panel divide-y divide-cu-line">
            <li v-for="order in orders" :key="order.id">
                <RouterLink :to="`/orders/${order.id}`" class="block px-5 py-4 active:bg-slate-50">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="text-xl font-bold">{{ longDate(order.created_at) }}</span>
                        <span class="cu-num text-xl font-bold">{{ money(order.total_amount) }}</span>
                    </div>
                    <p class="mt-1 truncate text-lg text-cu-muted">{{ order.items.map((i) => `${i.product_name} ${i.spec}`).join('、') }}</p>
                    <p class="mt-2 flex flex-wrap gap-2 text-lg">
                        <span class="rounded px-2" :class="statusClass[order.status]">{{ orderStatusText[order.status] }}</span>
                        <span v-if="order.status !== 'expired'" class="rounded px-2" :class="order.payment?.status === 'paid' ? 'bg-green-100' : 'bg-slate-100'">
                            {{ order.payment?.status === 'paid' ? '已付款' : '還沒付款' }}
                        </span>
                    </p>
                </RouterLink>
            </li>
        </ul>
        <button v-if="meta && meta.current_page < meta.last_page" class="cu-btn cu-btn-plain mt-4" @click="load(meta.current_page + 1)">看更早的訂單</button>
    </div>
</template>
