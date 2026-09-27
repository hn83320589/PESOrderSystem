<script setup>
import { ref } from 'vue';
import { money, toLocalInput } from '../../shared/format';

const props = defineProps({
    count: { type: Number, required: true },
    amount: { type: Number, required: true },
    defaultMethod: { type: String, default: 'bank_transfer' },
    error: { type: String, default: '' },
});
const emit = defineEmits(['submit']);

const form = ref({ method: props.defaultMethod, paid_at: toLocalInput(new Date()), check_no: '', check_due_date: '', note: '' });

function submit() {
    const body = { ...form.value };
    if (body.method !== 'check') {
        delete body.check_no;
        delete body.check_due_date;
    }
    emit('submit', body);
}
</script>

<template>
    <form class="space-y-3" @submit.prevent="submit">
        <p class="rounded bg-blue-50 p-2">共 {{ count }} 筆，合計 <strong>{{ money(amount) }}</strong></p>
        <p v-if="error" class="alert-error">{{ error }}</p>
        <div>
            <label class="label">實收方式</label>
            <select v-model="form.method" class="input">
                <option value="bank_transfer">匯款</option>
                <option value="cash_on_delivery">現金</option>
                <option value="check">支票</option>
            </select>
        </div>
        <div v-if="form.method === 'check'" class="grid grid-cols-2 gap-2">
            <div><label class="label">支票號碼</label><input v-model="form.check_no" class="input" required /></div>
            <div><label class="label">兌現日</label><input v-model="form.check_due_date" type="date" class="input" required /></div>
        </div>
        <div><label class="label">收款時間</label><input v-model="form.paid_at" type="datetime-local" class="input" required /></div>
        <div><label class="label">備註</label><input v-model="form.note" class="input" placeholder="例：匯款末五碼 12345" /></div>
        <button class="btn btn-primary w-full">確認已收款</button>
    </form>
</template>
