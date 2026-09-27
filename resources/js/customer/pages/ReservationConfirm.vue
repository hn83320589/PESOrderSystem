<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../../shared/http';
import { errorMessage } from '../../shared/format';
import { defaultPayment, longDate, money, paymentOptions } from '../store';

const route = useRoute();
const router = useRouter();
const reservation = ref(null);
const payment = ref(defaultPayment());
const error = ref('');
const sending = ref(false);
const requestId = crypto.randomUUID();

onMounted(async () => {
    const list = (await api('GET', '/api/customer/reservations')).data;
    reservation.value = list.find((r) => String(r.id) === route.params.id) ?? null;
    if (!reservation.value) error.value = '這筆預留已經到期或處理過了。';
});

const total = computed(() => (reservation.value ? reservation.value.price * reservation.value.quantity : 0));

async function confirmOrder() {
    if (sending.value) return;
    sending.value = true;
    error.value = '';
    try {
        const { data } = await api('POST', `/api/customer/reservations/${reservation.value.id}/confirm`, {
            payment_method: payment.value,
            request_id: requestId,
        });
        router.replace(`/done/${data.id}`);
    } catch (e) {
        error.value = errorMessage(e);
        sending.value = false;
    }
}
</script>

<template>
    <div class="space-y-4 px-4 pt-4">
        <p v-if="error" class="cu-panel border-l-8 border-cu-slip p-4 text-lg" role="alert">{{ error }}</p>
        <template v-if="reservation">
            <section class="cu-panel border-t-8 border-cu-slip p-5">
                <p class="text-lg text-cu-muted">店家幫您留了</p>
                <p class="mt-1 text-2xl font-bold">{{ reservation.product_name }} {{ reservation.spec }}</p>
                <p class="cu-num mt-2 text-xl">{{ reservation.quantity }} {{ reservation.unit }} × {{ money(reservation.price) }} ＝ <strong class="text-cu-slip">{{ money(total) }}</strong></p>
                <p class="mt-3 rounded bg-cu-caution px-3 py-2 text-lg">{{ longDate(reservation.expires_at) }} 前沒確認，這批貨會讓給別人。</p>
            </section>

            <fieldset class="cu-panel p-5">
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

            <button class="cu-btn cu-btn-primary min-h-20 text-xl" :disabled="sending" @click="confirmOrder">
                {{ sending ? '送出中，請稍等…' : '要這批貨，送出訂單' }}
            </button>
            <p class="text-lg text-cu-muted">數量要改，請先送出後打電話給店家，或直接去「挑商品叫貨」。</p>
        </template>
    </div>
</template>
