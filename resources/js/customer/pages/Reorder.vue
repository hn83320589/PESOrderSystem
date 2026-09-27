<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../../shared/http';
import { longDate, money, replaceCart } from '../store';

const router = useRouter();
const orders = ref(null);

onMounted(async () => {
    orders.value = (await api('GET', '/api/customer/recent-orders')).data;
});

// 帶入購物車：停售的不帶入，改價與缺貨標示提醒，最後一律到叫貨單確認
function reorder(order) {
    const lines = order.items
        .filter((item) => item.current?.orderable)
        .map((item) => ({
            variant_id: item.variant_id,
            name: item.product_name,
            spec: item.spec,
            unit: item.unit,
            price: item.current.price,
            quantity: item.quantity,
            warning: [
                item.current.price !== item.unit_price ? `價格有調整：上次 ${money(item.unit_price)}，現在 ${money(item.current.price)}` : null,
                item.current.in_stock ? null : '目前缺貨，送出後店家會跟您聯絡',
            ].filter(Boolean).join('；'),
        }));
    const discontinued = order.items.filter((item) => !item.current?.orderable).map((i) => `${i.product_name} ${i.spec}`);

    replaceCart(lines);
    router.push({ path: '/review', query: discontinued.length ? { discontinued: discontinued.join('、') } : {} });
}
</script>

<template>
    <div class="space-y-4 px-4 pt-4">
        <p v-if="orders && !orders.length" class="cu-panel p-5 text-lg">
            您還沒有叫過貨。<RouterLink to="/catalog" class="font-bold text-cu-pipe underline">去挑商品</RouterLink>
        </p>

        <article v-for="order in orders" :key="order.id" class="cu-panel p-5">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="text-xl font-bold">{{ longDate(order.created_at) }} 叫的貨</h2>
                <span class="cu-num text-lg text-cu-muted">{{ money(order.total_amount) }}</span>
            </div>
            <ul class="mt-3 space-y-1 text-lg">
                <li v-for="item in order.items" :key="item.id" class="flex justify-between gap-3" :class="{ 'text-cu-muted line-through': !item.current?.orderable }">
                    <span>{{ item.product_name }} {{ item.spec }}</span>
                    <span class="cu-num shrink-0">{{ item.quantity }} {{ item.unit }}</span>
                </li>
            </ul>
            <button class="cu-btn cu-btn-primary mt-4" @click="reorder(order)">照這張再叫一次</button>
        </article>
    </div>
</template>
