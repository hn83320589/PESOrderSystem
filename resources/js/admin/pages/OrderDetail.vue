<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '../../shared/http';
import { dateTime, errorMessage, money } from '../../shared/format';

const route = useRoute();
const order = ref(null);
const error = ref('');
const busy = ref(false);

async function load() {
    order.value = (await api('GET', `/api/admin/orders/${route.params.id}`)).data;
}
onMounted(load);

const editable = computed(() => ['pending', 'confirmed'].includes(order.value?.status) && order.value?.payment?.status !== 'paid');

async function act(action, confirmText) {
    if (confirmText && !confirm(confirmText)) return;
    busy.value = true;
    error.value = '';
    try {
        order.value = (await api('POST', `/api/admin/orders/${order.value.id}/${action}`)).data;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div v-if="order" class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <RouterLink to="/orders" class="btn">← 訂單列表</RouterLink>
            <h1 class="text-xl font-bold">訂單 {{ order.order_no }}</h1>
            <span class="rounded bg-gray-200 px-2 py-0.5 text-sm">{{ order.status_label }}</span>
        </div>
        <p v-if="error" class="alert-error">{{ error }}</p>

        <div class="flex flex-wrap gap-2">
            <button v-if="order.status === 'pending'" class="btn btn-primary" :disabled="busy" @click="act('confirm')">確認訂單</button>
            <button v-if="order.status === 'confirmed'" class="btn btn-primary" :disabled="busy" @click="act('ship', '確定已出貨？將扣除實際庫存。')">標記已出貨</button>
            <a class="btn" :href="`/api/admin/orders/${order.id}/pdf`" target="_blank" @click="order.print_count++">
                {{ order.print_count ? `補印訂單（已印 ${order.print_count} 次）` : '列印訂單' }}
            </a>
            <RouterLink v-if="editable" class="btn" :to="`/orders/${order.id}/edit`">修改訂單</RouterLink>
            <button v-if="['pending', 'confirmed'].includes(order.status)" class="btn btn-danger" :disabled="busy"
                @click="act('expire', '確定將此訂單設為失效？佔用的庫存會釋放。')">設為失效</button>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <dl class="space-y-1 rounded bg-white p-4 text-sm shadow">
                <div><dt class="inline text-gray-500">客戶：</dt><dd class="inline font-medium">{{ order.customer.name }}</dd></div>
                <div><dt class="inline text-gray-500">付款方式：</dt><dd class="inline">{{ order.payment_method_label }}</dd></div>
                <div><dt class="inline text-gray-500">收款狀態：</dt><dd class="inline">{{ order.payment?.status_label }}</dd></div>
                <div><dt class="inline text-gray-500">來源：</dt><dd class="inline">{{ order.source === 'customer' ? '客戶自行下單' : `代客下單（${order.created_by ?? ''}）` }}</dd></div>
                <div v-if="order.note"><dt class="inline text-gray-500">備註：</dt><dd class="inline">{{ order.note }}</dd></div>
            </dl>
            <dl class="space-y-1 rounded bg-white p-4 text-sm shadow md:col-span-2">
                <div><dt class="inline text-gray-500">下單：</dt><dd class="inline">{{ dateTime(order.created_at) }}</dd></div>
                <div v-if="order.confirmed_at"><dt class="inline text-gray-500">確認：</dt><dd class="inline">{{ dateTime(order.confirmed_at) }}</dd></div>
                <div v-if="order.shipped_at"><dt class="inline text-gray-500">出貨：</dt><dd class="inline">{{ dateTime(order.shipped_at) }}</dd></div>
                <div v-if="order.expired_at"><dt class="inline text-gray-500">失效：</dt><dd class="inline">{{ dateTime(order.expired_at) }}</dd></div>
            </dl>
        </div>

        <div class="overflow-x-auto rounded shadow">
            <table class="table">
                <thead><tr><th>品名</th><th>規格</th><th class="text-right">單價</th><th class="text-right">數量</th><th class="text-right">小計</th></tr></thead>
                <tbody>
                    <tr v-for="item in order.items" :key="item.id">
                        <td>{{ item.product_name }}</td>
                        <td>{{ item.spec }}</td>
                        <td class="text-right">{{ money(item.unit_price) }}</td>
                        <td class="text-right">{{ item.quantity }} {{ item.unit }}</td>
                        <td class="text-right">{{ money(item.subtotal) }}</td>
                    </tr>
                </tbody>
                <tfoot><tr><td colspan="4" class="text-right font-bold">合計</td><td class="text-right text-lg font-bold">{{ money(order.total_amount) }}</td></tr></tfoot>
            </table>
        </div>
    </div>
</template>
