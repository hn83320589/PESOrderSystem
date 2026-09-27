<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '../../shared/http';
import { longDate, money, orderStatusText, shop } from '../store';

const route = useRoute();
const order = ref(null);
const notFound = ref(false);

onMounted(async () => {
    try {
        order.value = (await api('GET', `/api/customer/orders/${route.params.id}`)).data;
    } catch {
        notFound.value = true;
    }
});

const steps = [
    { key: 'created_at', label: '送出訂單' },
    { key: 'confirmed_at', label: '店家已確認' },
    { key: 'shipped_at', label: '已出貨' },
];
</script>

<template>
    <div class="space-y-4 px-4 pt-4">
        <p v-if="notFound" class="cu-panel p-5 text-lg">找不到這張訂單。</p>
        <template v-if="order">
            <section class="cu-panel p-5">
                <p class="text-lg text-cu-muted">訂單編號</p>
                <p class="cu-num text-2xl font-bold whitespace-nowrap text-cu-slip">{{ order.order_no }}</p>
                <p v-if="order.status === 'expired'" class="mt-3 rounded bg-slate-200 px-3 py-2 text-lg">這張訂單已取消，有問題請打給店家。</p>
                <ol v-else class="mt-4 space-y-2">
                    <li v-for="step in steps" :key="step.key" class="flex items-center gap-3 text-lg" :class="order[step.key] ? 'font-bold' : 'text-cu-muted'">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full border-2" :class="order[step.key] ? 'border-green-700 bg-green-700 text-white' : 'border-cu-line'">
                            {{ order[step.key] ? '✓' : '' }}
                        </span>
                        {{ step.label }}<span v-if="order[step.key]" class="font-normal text-cu-muted">（{{ longDate(order[step.key]) }}）</span>
                    </li>
                </ol>
            </section>

            <section class="cu-panel">
                <ul class="divide-y divide-cu-line">
                    <li v-for="item in order.items" :key="item.id" class="flex justify-between gap-3 px-5 py-3 text-lg">
                        <span>{{ item.product_name }} {{ item.spec }}<br /><span class="cu-num text-cu-muted">{{ money(item.unit_price) }} × {{ item.quantity }} {{ item.unit }}</span></span>
                        <span class="cu-num shrink-0 font-bold">{{ money(item.subtotal) }}</span>
                    </li>
                </ul>
                <div class="flex items-baseline justify-between border-t-2 border-cu-ink px-5 py-4">
                    <span class="text-xl font-bold">合計</span>
                    <strong class="cu-num text-2xl">{{ money(order.total_amount) }}</strong>
                </div>
                <p class="border-t border-cu-line px-5 py-3 text-lg">
                    付款：{{ order.payment_method_label }}，{{ order.payment?.status === 'paid' ? '已付款' : '還沒付款' }}
                </p>
                <p v-if="order.note" class="border-t border-cu-line px-5 py-3 text-lg">備註：{{ order.note }}</p>
            </section>

            <a :href="`/api/customer/orders/${order.id}/pdf`" target="_blank" class="cu-btn cu-btn-plain">看／印訂貨單</a>
            <a v-if="shop.phone" :href="`tel:${shop.phone}`" class="cu-btn cu-btn-plain">要改訂單請打給店家</a>
        </template>
    </div>
</template>
