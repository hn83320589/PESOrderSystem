<script setup>
import { computed, onMounted, ref } from 'vue';
import { api } from '../../shared/http';
import { dateTime, errorMessage, toLocalInput } from '../../shared/format';
import Modal from '../components/Modal.vue';

const statuses = [
    { value: 'active', label: '預留中' },
    { value: 'fulfilled', label: '已下單' },
    { value: 'expired', label: '已到期釋放' },
    { value: 'cancelled', label: '已取消' },
];
const status = ref('active');
const reservations = ref([]);
const error = ref('');

async function load() {
    reservations.value = (await api('GET', `/api/admin/reservations?status=${status.value}`)).data;
}
onMounted(load);

const daysLeft = (expiresAt) => Math.ceil((new Date(expiresAt) - Date.now()) / 86400000);

// 新增預留
const customers = ref([]);
const variants = ref([]);
const creating = ref(null);
const selectedVariant = computed(() => variants.value.find((v) => v.id === creating.value?.product_variant_id));
async function openCreate() {
    error.value = '';
    const defaultExpiry = new Date(Date.now() + 30 * 86400000);
    defaultExpiry.setHours(18, 0, 0, 0);
    creating.value = { customer_id: null, product_variant_id: null, quantity: null, expires_at: toLocalInput(defaultExpiry), note: '' };
    [customers.value, variants.value] = await Promise.all([
        api('GET', '/api/admin/customers').then((r) => r.data),
        api('GET', '/api/admin/inventory').then((r) => r.data),
    ]);
}
async function submitCreate() {
    try {
        await api('POST', '/api/admin/reservations', creating.value);
        creating.value = null;
        status.value = 'active';
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function cancel(reservation) {
    if (!confirm(`確定取消「${reservation.customer.name}」的這筆預留？庫存將釋放給其他客戶。`)) return;
    try {
        await api('POST', `/api/admin/reservations/${reservation.id}/cancel`);
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

// 複製為下一期
const renewing = ref(null);
function openRenew(reservation) {
    const next = new Date(reservation.expires_at);
    next.setDate(next.getDate() + 30);
    renewing.value = { reservation, expires_at: toLocalInput(next) };
    error.value = '';
}
async function submitRenew() {
    try {
        await api('POST', `/api/admin/reservations/${renewing.value.reservation.id}/renew`, { expires_at: renewing.value.expires_at });
        renewing.value = null;
        status.value = 'active';
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-bold">熟客預留</h1>
            <select v-model="status" class="input w-36" @change="load">
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
            <button class="btn btn-primary" @click="openCreate">＋ 新增預留</button>
        </div>
        <p class="text-sm text-gray-500">到期前 7 天系統會提醒客戶；到期未下單自動釋放庫存。</p>
        <p v-if="error && !creating && !renewing" class="alert-error">{{ error }}</p>

        <div class="overflow-x-auto rounded shadow">
            <table class="table">
                <thead>
                    <tr><th>客戶</th><th>商品</th><th class="text-right">數量</th><th>到期時間</th><th>已提醒</th><th>備註</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="r in reservations" :key="r.id">
                        <td>{{ r.customer.name }}</td>
                        <td>{{ r.variant.product_name }} {{ r.variant.spec }}</td>
                        <td class="text-right">{{ r.quantity }} {{ r.variant.unit }}</td>
                        <td class="whitespace-nowrap">
                            {{ dateTime(r.expires_at) }}
                            <span v-if="r.status === 'active'" class="ml-1 text-xs" :class="daysLeft(r.expires_at) <= 7 ? 'text-red-600' : 'text-gray-400'">
                                剩 {{ daysLeft(r.expires_at) }} 天
                            </span>
                        </td>
                        <td>{{ r.reminded_at ? dateTime(r.reminded_at) : '—' }}</td>
                        <td>{{ r.note }}</td>
                        <td class="space-x-1 text-right whitespace-nowrap">
                            <button class="btn" @click="openRenew(r)">複製下一期</button>
                            <button v-if="r.status === 'active'" class="btn btn-danger" @click="cancel(r)">取消</button>
                        </td>
                    </tr>
                    <tr v-if="!reservations.length"><td colspan="7" class="py-8 text-center text-gray-400">沒有資料</td></tr>
                </tbody>
            </table>
        </div>

        <Modal v-if="creating" title="新增熟客預留" @close="creating = null">
            <form class="space-y-3" @submit.prevent="submitCreate">
                <p v-if="error" class="alert-error">{{ error }}</p>
                <div>
                    <label class="label">客戶</label>
                    <select v-model="creating.customer_id" class="input" required>
                        <option :value="null" disabled>請選擇</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="label">商品規格</label>
                    <select v-model="creating.product_variant_id" class="input" required>
                        <option :value="null" disabled>請選擇</option>
                        <option v-for="v in variants" :key="v.id" :value="v.id">{{ v.product_name }} {{ v.spec }}（可用 {{ v.stock.available }}）</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="label">預留數量</label>
                        <input v-model.number="creating.quantity" type="number" min="1" :max="selectedVariant?.stock.available" class="input" required />
                    </div>
                    <div>
                        <label class="label">到期時間</label>
                        <input v-model="creating.expires_at" type="datetime-local" class="input" required />
                    </div>
                </div>
                <div><label class="label">備註</label><input v-model="creating.note" class="input" placeholder="例：每月固定叫貨" /></div>
                <button class="btn btn-primary w-full">建立預留</button>
            </form>
        </Modal>

        <Modal v-if="renewing" title="複製為下一期" @close="renewing = null">
            <form class="space-y-3" @submit.prevent="submitRenew">
                <p v-if="error" class="alert-error">{{ error }}</p>
                <p>
                    {{ renewing.reservation.customer.name }}：{{ renewing.reservation.variant.product_name }}
                    {{ renewing.reservation.variant.spec }} × {{ renewing.reservation.quantity }}
                </p>
                <div><label class="label">新的到期時間</label><input v-model="renewing.expires_at" type="datetime-local" class="input" required /></div>
                <button class="btn btn-primary w-full">建立下一期預留</button>
            </form>
        </Modal>
    </div>
</template>
