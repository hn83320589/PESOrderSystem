<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../../shared/http';
import { dateTime, errorMessage } from '../../shared/format';
import Modal from '../components/Modal.vue';

const customers = ref([]);
const keyword = ref('');
const error = ref('');

async function load() {
    const params = keyword.value ? `?q=${encodeURIComponent(keyword.value)}` : '';
    customers.value = (await api('GET', `/api/admin/customers${params}`)).data;
}
onMounted(load);

const editing = ref(null);
function openEdit(customer = null) {
    error.value = '';
    editing.value = customer
        ? { ...customer }
        : { name: '', contact_name: '', phone: '', address: '', billing_type: 'cash_on_delivery', note: '', is_active: true };
}
async function save() {
    const { id, name, contact_name, phone, address, billing_type, note, is_active } = editing.value;
    const body = { name, contact_name, phone, address, billing_type, note, is_active };
    try {
        await (id ? api('PATCH', `/api/admin/customers/${id}`, body) : api('POST', '/api/admin/customers', body));
        editing.value = null;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

const bindLink = ref(null);
async function issueBindLink(customer) {
    const { data } = await api('POST', `/api/admin/customers/${customer.id}/line-bind-link`);
    bindLink.value = { customer, ...data, copied: false };
}
async function copyBindLink() {
    await navigator.clipboard.writeText(bindLink.value.url);
    bindLink.value.copied = true;
}
async function unbind(customer) {
    if (!confirm(`確定解除「${customer.name}」的 LINE 綁定？解除後客戶需重新綁定才能線上下單。`)) return;
    await api('DELETE', `/api/admin/customers/${customer.id}/line-binding`);
    await load();
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-bold">客戶</h1>
            <input v-model="keyword" class="input max-w-xs" placeholder="搜尋名稱、聯絡人、電話" @keyup.enter="load" />
            <button class="btn" @click="load">搜尋</button>
            <button class="btn btn-primary" @click="openEdit()">＋ 新增客戶</button>
        </div>

        <div class="overflow-x-auto rounded shadow">
            <table class="table">
                <thead>
                    <tr><th>名稱</th><th>聯絡人</th><th>電話</th><th>結帳方式</th><th>LINE</th><th>備註</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="c in customers" :key="c.id" :class="{ 'text-gray-400': !c.is_active }">
                        <td class="font-medium">{{ c.name }}</td>
                        <td>{{ c.contact_name }}</td>
                        <td>{{ c.phone }}</td>
                        <td>{{ c.billing_type_label }}</td>
                        <td>
                            <span v-if="c.line_bound && c.line_is_friend === false" class="text-red-600" title="客戶已封鎖或刪除官方帳號，LINE 通知會發送失敗">
                                已綁定，但已封鎖官方帳號
                            </span>
                            <span v-else-if="c.line_bound" class="text-green-700">已綁定 {{ c.line_display_name }}</span>
                            <span v-else class="text-gray-400">未綁定</span>
                        </td>
                        <td class="max-w-48 truncate">{{ c.note }}</td>
                        <td class="space-x-1 text-right whitespace-nowrap">
                            <RouterLink class="btn" :to="{ name: 'orders', query: { customer_id: c.id } }">訂單</RouterLink>
                            <button class="btn" @click="openEdit(c)">編輯</button>
                            <button v-if="!c.line_bound" class="btn" @click="issueBindLink(c)">產生綁定連結</button>
                            <button v-else class="btn btn-danger" @click="unbind(c)">解除綁定</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal v-if="editing" :title="editing.id ? `編輯：${editing.name}` : '新增客戶'" @close="editing = null">
            <form class="space-y-3" @submit.prevent="save">
                <p v-if="error" class="alert-error">{{ error }}</p>
                <div><label class="label">名稱（店名）</label><input v-model="editing.name" class="input" required /></div>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="label">聯絡人</label><input v-model="editing.contact_name" class="input" /></div>
                    <div><label class="label">電話</label><input v-model="editing.phone" class="input" /></div>
                </div>
                <div><label class="label">送貨地址</label><input v-model="editing.address" class="input" /></div>
                <div>
                    <label class="label">結帳方式</label>
                    <label class="mr-4"><input v-model="editing.billing_type" type="radio" value="monthly" /> 月結</label>
                    <label><input v-model="editing.billing_type" type="radio" value="cash_on_delivery" /> 貨到付款</label>
                </div>
                <div><label class="label">備註</label><textarea v-model="editing.note" class="input" rows="2"></textarea></div>
                <label v-if="editing.id" class="flex items-center gap-1 text-sm"><input v-model="editing.is_active" type="checkbox" /> 往來中</label>
                <button class="btn btn-primary w-full">儲存</button>
            </form>
        </Modal>

        <Modal v-if="bindLink" :title="`LINE 綁定連結：${bindLink.customer.name}`" @close="bindLink = null">
            <div class="space-y-3">
                <p class="text-sm">請將以下連結用 LINE 傳給客戶，客戶點開並用 LINE 登入後即完成綁定。</p>
                <p class="rounded bg-gray-100 p-2 text-sm break-all">{{ bindLink.url }}</p>
                <p class="text-sm text-gray-500">有效期限至 {{ dateTime(bindLink.expires_at) }}，只能使用一次；重新產生會讓舊連結失效。</p>
                <button class="btn btn-primary w-full" @click="copyBindLink">{{ bindLink.copied ? '已複製 ✓' : '複製連結' }}</button>
            </div>
        </Modal>
    </div>
</template>
