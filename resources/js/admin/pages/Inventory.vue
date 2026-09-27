<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../../shared/http';
import { dateTime, errorMessage } from '../../shared/format';
import Modal from '../components/Modal.vue';

const variants = ref([]);
const keyword = ref('');
const lowStockOnly = ref(false);
const LOW_STOCK_THRESHOLD = 10;
const loadError = ref('');

async function load() {
    const params = new URLSearchParams();
    if (keyword.value) params.set('q', keyword.value);
    if (lowStockOnly.value) params.set('low_stock', LOW_STOCK_THRESHOLD);
    try {
        variants.value = (await api('GET', `/api/admin/inventory?${params}`)).data;
        loadError.value = '';
    } catch (e) {
        loadError.value = errorMessage(e);
    }
}
onMounted(load);

// 庫存調整
const adjusting = ref(null);
const adjustForm = ref({ delta: null, note: '' });
const adjustError = ref('');
function openAdjust(variant) {
    adjusting.value = variant;
    adjustForm.value = { delta: null, note: '' };
    adjustError.value = '';
}
async function submitAdjust() {
    try {
        const { data } = await api('POST', `/api/admin/inventory/${adjusting.value.id}/adjust`, adjustForm.value);
        adjusting.value.stock = data;
        adjusting.value = null;
    } catch (e) {
        adjustError.value = errorMessage(e);
    }
}

// 異動紀錄
const history = ref(null);
async function openHistory(variant) {
    history.value = { variant, movements: [] };
    history.value.movements = (await api('GET', `/api/admin/inventory/${variant.id}/movements`)).data;
}
const signed = (n) => (n > 0 ? `+${n}` : n || '');
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-bold">庫存</h1>
            <input v-model="keyword" class="input max-w-xs" placeholder="搜尋品名、規格、料號" @keyup.enter="load" />
            <button class="btn" @click="load">搜尋</button>
            <label class="flex items-center gap-1 text-sm">
                <input v-model="lowStockOnly" type="checkbox" @change="load" /> 只看可用量低於 {{ LOW_STOCK_THRESHOLD }}
            </label>
        </div>
        <p v-if="loadError" class="alert-error">{{ loadError }}</p>
        <p class="text-sm text-gray-500">可用量 = 實際庫存 − 熟客預留 − 已下單未出貨</p>

        <div class="overflow-x-auto rounded shadow">
            <table class="table">
                <thead>
                    <tr>
                        <th>品名</th>
                        <th>規格</th>
                        <th>料號</th>
                        <th class="text-right">實際庫存</th>
                        <th class="text-right">熟客預留</th>
                        <th class="text-right">已下單未出貨</th>
                        <th class="text-right">可用量</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="v in variants" :key="v.id">
                        <td>{{ v.product_name }}</td>
                        <td>{{ v.spec }}</td>
                        <td class="text-gray-500">{{ v.sku }}</td>
                        <td class="text-right">{{ v.stock.on_hand }}</td>
                        <td class="text-right">{{ v.stock.reserved }}</td>
                        <td class="text-right">{{ v.stock.allocated }}</td>
                        <td class="text-right font-bold" :class="{ 'text-red-600': v.stock.available < LOW_STOCK_THRESHOLD }">
                            {{ v.stock.available }}
                        </td>
                        <td class="space-x-1 text-right whitespace-nowrap">
                            <button class="btn" @click="openAdjust(v)">調整</button>
                            <button class="btn" @click="openHistory(v)">紀錄</button>
                        </td>
                    </tr>
                    <tr v-if="!variants.length">
                        <td colspan="8" class="py-8 text-center text-gray-400">沒有資料</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal v-if="adjusting" :title="`調整庫存：${adjusting.product_name} ${adjusting.spec}`" @close="adjusting = null">
            <form class="space-y-3" @submit.prevent="submitAdjust">
                <p class="text-sm">目前實際庫存 {{ adjusting.stock.on_hand }}，可用量 {{ adjusting.stock.available }}</p>
                <p v-if="adjustError" class="alert-error">{{ adjustError }}</p>
                <div>
                    <label class="label">增減數量（進貨填正數，盤點短少填負數）</label>
                    <input v-model.number="adjustForm.delta" type="number" class="input" required />
                </div>
                <div>
                    <label class="label">原因</label>
                    <input v-model="adjustForm.note" class="input" placeholder="例：進貨、盤點短少、損壞" required />
                </div>
                <button class="btn btn-primary w-full">確認調整</button>
            </form>
        </Modal>

        <Modal v-if="history" :title="`異動紀錄：${history.variant.product_name} ${history.variant.spec}`" @close="history = null">
            <div class="max-h-[60vh] overflow-y-auto">
                <table class="table">
                    <thead>
                        <tr><th>時間</th><th>類型</th><th class="text-right">實際</th><th class="text-right">預留</th><th class="text-right">佔用</th><th>人員／備註</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in history.movements" :key="m.id">
                            <td class="whitespace-nowrap">{{ dateTime(m.created_at) }}</td>
                            <td>{{ m.type_label }}</td>
                            <td class="text-right">{{ signed(m.on_hand_change) }}<br /><span class="text-xs text-gray-400">→{{ m.on_hand_after }}</span></td>
                            <td class="text-right">{{ signed(m.reserved_change) }}<br /><span class="text-xs text-gray-400">→{{ m.reserved_after }}</span></td>
                            <td class="text-right">{{ signed(m.allocated_change) }}<br /><span class="text-xs text-gray-400">→{{ m.allocated_after }}</span></td>
                            <td>{{ m.user_name }} {{ m.note }}</td>
                        </tr>
                        <tr v-if="!history.movements.length"><td colspan="6" class="text-center text-gray-400">尚無紀錄</td></tr>
                    </tbody>
                </table>
            </div>
        </Modal>
    </div>
</template>
