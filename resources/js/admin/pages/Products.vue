<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../../shared/http';
import { errorMessage, money } from '../../shared/format';
import Modal from '../components/Modal.vue';

const products = ref([]);
const error = ref('');

async function load() {
    products.value = (await api('GET', '/api/admin/products')).data;
}
onMounted(load);

// 新增商品（含規格）
const creating = ref(null);
function openCreate() {
    creating.value = { name: '', category: '', unit: '個', variants: [{ spec: '', price: null, sku: '' }] };
    error.value = '';
}
async function submitCreate() {
    try {
        await api('POST', '/api/admin/products', creating.value);
        creating.value = null;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

// 編輯商品
const editing = ref(null);
function openEdit(product) {
    editing.value = { ...product, newVariant: { spec: '', price: null, sku: '' } };
    error.value = '';
}
async function saveProduct() {
    try {
        const { name, category, unit, is_active } = editing.value;
        await api('PATCH', `/api/admin/products/${editing.value.id}`, { name, category, unit, is_active });
        editing.value = null;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}
async function saveVariant(variant) {
    try {
        const { spec, price, sku, is_active } = variant;
        await api('PATCH', `/api/admin/variants/${variant.id}`, { spec, price, sku: sku || null, is_active });
        error.value = '';
    } catch (e) {
        error.value = errorMessage(e);
    }
}
async function addVariant() {
    try {
        const { data } = await api('POST', `/api/admin/products/${editing.value.id}/variants`, editing.value.newVariant);
        editing.value.variants.push(data);
        editing.value.newVariant = { spec: '', price: null, sku: '' };
        error.value = '';
    } catch (e) {
        error.value = errorMessage(e);
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold">商品</h1>
            <button class="btn btn-primary" @click="openCreate">＋ 新增商品</button>
        </div>

        <div class="overflow-x-auto rounded shadow">
            <table class="table">
                <thead>
                    <tr><th>分類</th><th>品名</th><th>單位</th><th>規格／單價</th><th>狀態</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="p in products" :key="p.id" :class="{ 'text-gray-400': !p.is_active }">
                        <td>{{ p.category }}</td>
                        <td class="font-medium">{{ p.name }}</td>
                        <td>{{ p.unit }}</td>
                        <td>
                            <span v-for="v in p.variants" :key="v.id" class="mr-2 inline-block" :class="{ 'line-through': !v.is_active }">
                                {{ v.spec }} {{ money(v.price) }}
                            </span>
                        </td>
                        <td>{{ p.is_active ? '販售中' : '已停售' }}</td>
                        <td class="text-right"><button class="btn" @click="openEdit(p)">編輯</button></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal v-if="creating" title="新增商品" @close="creating = null">
            <form class="space-y-3" @submit.prevent="submitCreate">
                <p v-if="error" class="alert-error">{{ error }}</p>
                <div class="grid grid-cols-3 gap-2">
                    <div class="col-span-3"><label class="label">品名</label><input v-model="creating.name" class="input" required /></div>
                    <div><label class="label">分類</label><input v-model="creating.category" class="input" placeholder="水／電" /></div>
                    <div><label class="label">單位</label><input v-model="creating.unit" class="input" required /></div>
                </div>
                <div>
                    <label class="label">規格（單價以「元」為單位）</label>
                    <div v-for="(v, i) in creating.variants" :key="i" class="mb-2 flex gap-2">
                        <input v-model="v.spec" class="input" placeholder="規格，例 3/4&quot;" required />
                        <input v-model.number="v.price" type="number" min="0" class="input w-28" placeholder="單價" required />
                        <input v-model="v.sku" class="input w-28" placeholder="料號(選填)" />
                        <button type="button" class="btn btn-danger" :disabled="creating.variants.length === 1" @click="creating.variants.splice(i, 1)">刪</button>
                    </div>
                    <button type="button" class="btn" @click="creating.variants.push({ spec: '', price: null, sku: '' })">＋ 再加一個規格</button>
                </div>
                <p class="text-sm text-gray-500">新規格的庫存為 0，請到「庫存」頁以「調整」登記進貨。</p>
                <button class="btn btn-primary w-full">建立</button>
            </form>
        </Modal>

        <Modal v-if="editing" :title="`編輯：${editing.name}`" @close="editing = null; load()">
            <div class="space-y-4">
                <p v-if="error" class="alert-error">{{ error }}</p>
                <form class="grid grid-cols-3 gap-2" @submit.prevent="saveProduct">
                    <div class="col-span-3"><label class="label">品名</label><input v-model="editing.name" class="input" required /></div>
                    <div><label class="label">分類</label><input v-model="editing.category" class="input" /></div>
                    <div><label class="label">單位</label><input v-model="editing.unit" class="input" required /></div>
                    <label class="flex items-end gap-1 pb-2 text-sm"><input v-model="editing.is_active" type="checkbox" /> 販售中</label>
                    <button class="btn btn-primary col-span-3">儲存商品資料</button>
                </form>
                <div>
                    <h3 class="label">規格（修改後按「存」）</h3>
                    <div v-for="v in editing.variants" :key="v.id" class="mb-2 flex items-center gap-2">
                        <input v-model="v.spec" class="input" />
                        <input v-model.number="v.price" type="number" min="0" class="input w-24" />
                        <input v-model="v.sku" class="input w-24" placeholder="料號" />
                        <label class="flex items-center gap-1 text-sm whitespace-nowrap"><input v-model="v.is_active" type="checkbox" />售</label>
                        <button class="btn" @click="saveVariant(v)">存</button>
                    </div>
                    <form class="flex gap-2 border-t pt-2" @submit.prevent="addVariant">
                        <input v-model="editing.newVariant.spec" class="input" placeholder="新規格" required />
                        <input v-model.number="editing.newVariant.price" type="number" min="0" class="input w-24" placeholder="單價" required />
                        <input v-model="editing.newVariant.sku" class="input w-24" placeholder="料號" />
                        <button class="btn">新增</button>
                    </form>
                </div>
            </div>
        </Modal>
    </div>
</template>
