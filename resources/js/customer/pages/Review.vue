<script setup>
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../../shared/http';
import { errorMessage } from '../../shared/format';
import QtyStepper from '../components/QtyStepper.vue';
import { cart, cartTotal, clearCart, defaultPayment, loadMe, me, money, paymentOptions, setQuantity, shop } from '../store';

const route = useRoute();
const router = useRouter();
const payment = ref(defaultPayment());
const note = ref('');
const error = ref('');
const sending = ref(false);
// 進到這頁就產生一次；重複按送出、網路重送都帶同一個，後端只會成立一張單
const requestId = crypto.randomUUID();

onMounted(() => {
    if (route.query.discontinued) {
        error.value = `以下商品已停售，沒有放進叫貨單：${route.query.discontinued}`;
    }
});

async function submit() {
    if (sending.value) return;
    sending.value = true;
    error.value = '';
    try {
        const { data } = await api('POST', '/api/customer/orders', {
            items: cart.items.map((i) => ({ variant_id: i.variant_id, quantity: i.quantity })),
            payment_method: payment.value,
            note: note.value || null,
            request_id: requestId,
        });
        clearCart();
        loadMe(true);
        router.replace(`/done/${data.id}`);
    } catch (e) {
        error.value = errorMessage(e);
        sending.value = false;
    }
}
</script>

<template>
    <div class="px-4 pt-4">
        <p v-if="error" class="cu-panel mb-4 border-l-8 border-cu-slip p-4 text-lg" role="alert">{{ error }}</p>

        <div v-if="!cart.items.length" class="cu-panel space-y-4 p-5 text-lg">
            <p>叫貨單是空的。</p>
            <RouterLink to="/reorder" class="cu-btn cu-btn-primary">照上次叫的貨</RouterLink>
            <RouterLink to="/catalog" class="cu-btn cu-btn-plain">挑商品叫貨</RouterLink>
        </div>

        <template v-else>
            <!-- 叫貨單：仿紙本三聯單，送出前最後確認 -->
            <section class="cu-panel overflow-hidden border-t-8 border-cu-slip">
                <div class="flex items-baseline justify-between gap-3 border-b-2 border-dashed border-cu-line px-5 py-4">
                    <h2 class="shrink-0 text-2xl font-bold tracking-wide">叫貨單</h2>
                    <span class="min-w-0 truncate text-lg text-cu-muted">{{ me.name }}</span>
                </div>
                <ul class="divide-y divide-cu-line">
                    <li v-for="item in cart.items" :key="item.variant_id" class="px-5 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-xl font-bold">{{ item.name }} <span class="whitespace-nowrap">{{ item.spec }}</span></p>
                            <button class="shrink-0 rounded-lg px-3 py-2 text-lg text-cu-slip active:bg-red-50" @click="setQuantity(item, 0)">不要了</button>
                        </div>
                        <p v-if="item.warning" class="mt-1 rounded bg-cu-caution px-3 py-1 text-lg">{{ item.warning }}</p>
                        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
                            <QtyStepper :model-value="item.quantity" :label="`${item.name} ${item.spec}`" :min="1" @update:model-value="setQuantity(item, $event)" />
                            <p class="cu-num text-lg">{{ money(item.price) }} × {{ item.quantity }} {{ item.unit }}<br /><strong class="text-xl">{{ money(item.price * item.quantity) }}</strong></p>
                        </div>
                    </li>
                </ul>
                <div class="flex items-baseline justify-between border-t-2 border-cu-ink px-5 py-4">
                    <span class="text-xl font-bold">合計</span>
                    <strong class="cu-num text-3xl text-cu-slip">{{ money(cartTotal) }}</strong>
                </div>
            </section>

            <fieldset class="cu-panel mt-4 p-5">
                <legend class="sr-only">付款方式</legend>
                <p class="mb-3 text-lg font-bold">怎麼付款</p>
                <div class="grid grid-cols-3 gap-2">
                    <label v-for="option in paymentOptions" :key="option.value"
                        class="flex min-h-14 cursor-pointer items-center justify-center rounded-lg border-2 text-lg font-bold"
                        :class="payment === option.value ? 'border-cu-pipe bg-cu-pipe text-white' : 'border-cu-line bg-white'">
                        <input v-model="payment" type="radio" name="payment" :value="option.value" class="sr-only" />{{ option.label }}
                    </label>
                </div>
            </fieldset>

            <label class="cu-panel mt-4 block p-5">
                <span class="mb-2 block text-lg font-bold">要跟店家說的話（可以不填）</span>
                <textarea v-model="note" rows="2" maxlength="500" class="cu-input" placeholder="例如：下午送、放後門"></textarea>
            </label>

            <button class="cu-btn cu-btn-primary mt-6 min-h-20 text-xl" :disabled="sending" @click="submit">
                {{ sending ? '送出中，請稍等…' : `確定送出（${money(cartTotal)}）` }}
            </button>
            <p class="mt-3 text-lg text-cu-muted">送出後由{{ shop.name }}確認，確認了會用 LINE 通知您。</p>
        </template>
    </div>
</template>
