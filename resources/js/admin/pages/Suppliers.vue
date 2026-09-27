<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../../shared/http';
import { errorMessage } from '../../shared/format';
import Modal from '../components/Modal.vue';

const suppliers = ref([]);
const editing = ref(null);
const error = ref('');

async function load() {
    suppliers.value = (await api('GET', '/api/admin/suppliers')).data;
}
onMounted(load);

function openEdit(supplier = null) {
    error.value = '';
    editing.value = supplier ? { ...supplier } : { name: '', contact_name: '', phone: '', note: '', is_active: true };
}
async function save() {
    const { id, name, contact_name, phone, note, is_active } = editing.value;
    try {
        await (id ? api('PATCH', `/api/admin/suppliers/${id}`, { name, contact_name, phone, note, is_active }) : api('POST', '/api/admin/suppliers', { name, contact_name, phone, note }));
        editing.value = null;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center gap-3">
            <RouterLink to="/purchases" class="btn">← 進貨</RouterLink>
            <h1 class="text-xl font-bold">供應商</h1>
            <button class="btn btn-primary" @click="openEdit()">＋ 新增供應商</button>
        </div>
        <div class="overflow-x-auto rounded shadow">
            <table class="table">
                <thead><tr><th>名稱</th><th>聯絡人</th><th>電話</th><th>備註</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="s in suppliers" :key="s.id" :class="{ 'text-gray-400': !s.is_active }">
                        <td class="font-medium">{{ s.name }}<span v-if="!s.is_active" class="ml-1 text-xs">（停用）</span></td>
                        <td>{{ s.contact_name }}</td>
                        <td>{{ s.phone }}</td>
                        <td class="max-w-64 truncate">{{ s.note }}</td>
                        <td class="text-right"><button class="btn" @click="openEdit(s)">編輯</button></td>
                    </tr>
                    <tr v-if="!suppliers.length"><td colspan="5" class="py-8 text-center text-gray-400">尚無供應商</td></tr>
                </tbody>
            </table>
        </div>

        <Modal v-if="editing" :title="editing.id ? `編輯：${editing.name}` : '新增供應商'" @close="editing = null">
            <form class="space-y-3" @submit.prevent="save">
                <p v-if="error" class="alert-error">{{ error }}</p>
                <div><label class="label">名稱</label><input v-model="editing.name" class="input" required /></div>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="label">聯絡人</label><input v-model="editing.contact_name" class="input" /></div>
                    <div><label class="label">電話</label><input v-model="editing.phone" class="input" /></div>
                </div>
                <div><label class="label">備註</label><textarea v-model="editing.note" class="input" rows="2" placeholder="例：每週二、五送貨；滿萬免運"></textarea></div>
                <label v-if="editing.id" class="flex items-center gap-1 text-sm"><input v-model="editing.is_active" type="checkbox" /> 往來中</label>
                <button class="btn btn-primary w-full">儲存</button>
            </form>
        </Modal>
    </div>
</template>
