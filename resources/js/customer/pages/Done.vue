<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '../../shared/http';
import { money } from '../store';

const route = useRoute();
const order = ref(null);

onMounted(async () => {
    order.value = (await api('GET', `/api/customer/orders/${route.params.id}`)).data;
});
</script>

<template>
    <div v-if="order" class="space-y-5 px-4 pt-6">
        <section class="cu-panel border-t-8 border-green-700 p-6" role="status">
            <p class="text-2xl font-bold">訂單已經送出了</p>
            <p class="mt-3 text-lg">訂單編號</p>
            <p class="cu-num text-[1.75rem] font-bold whitespace-nowrap text-cu-slip">{{ order.order_no }}</p>
            <p class="mt-3 text-lg">共 {{ order.items.length }} 項，{{ money(order.total_amount) }}</p>
            <p class="mt-4 text-lg">店家確認後會用 LINE 通知您，不用再打電話。</p>
        </section>
        <RouterLink :to="`/orders/${order.id}`" class="cu-btn cu-btn-plain">看這張訂單</RouterLink>
        <RouterLink to="/" class="cu-btn cu-btn-primary">回首頁</RouterLink>
    </div>
</template>
