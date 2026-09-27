<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '../../shared/http';
import { errorMessage } from '../../shared/format';
import { longDate, money, orderStatusText, shop } from '../store';

const route = useRoute();
const order = ref(null);
const notFound = ref(false);

// 存成常用組合（名稱可不填，預設「常用 N」）
const saving = ref(null); // null：未開啟；{ name }：填寫中
const savedMessage = ref('');
const saveError = ref('');
async function saveFavorite() {
    saveError.value = '';
    try {
        const { data } = await api('POST', '/api/customer/favorites', {
            name: saving.value.name || null,
            items: order.value.items.map((i) => ({ variant_id: i.variant_id, quantity: i.quantity })),
        });
        saving.value = null;
        savedMessage.value = `已存成「${data.name}」。下次按「照上次叫的貨」，最上面就找得到。`;
    } catch (e) {
        saveError.value = errorMessage(e);
    }
}

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

            <p v-if="savedMessage" class="cu-panel border-l-8 border-green-700 p-4 text-lg" role="status">{{ savedMessage }}</p>
            <button v-else-if="!saving" class="cu-btn cu-btn-plain" @click="saving = { name: '' }">把這張存成常用</button>
            <form v-else class="cu-panel space-y-3 p-5" @submit.prevent="saveFavorite">
                <label class="block">
                    <span class="mb-2 block text-lg font-bold">取個名字（可以不填）</span>
                    <input v-model="saving.name" maxlength="50" class="cu-input" placeholder="例如：工地標準包" />
                </label>
                <p v-if="saveError" class="text-lg text-cu-slip" role="alert">{{ saveError }}</p>
                <button class="cu-btn cu-btn-primary">存起來</button>
                <button type="button" class="cu-btn cu-btn-plain" @click="saving = null">不用了</button>
            </form>
            <a v-if="shop.phone" :href="`tel:${shop.phone}`" class="cu-btn cu-btn-plain">要改訂單請打給店家</a>
        </template>
    </div>
</template>
