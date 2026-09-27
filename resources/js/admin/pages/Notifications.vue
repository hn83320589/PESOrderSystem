<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../../shared/http';
import { dateTime, errorMessage } from '../../shared/format';

const statuses = [
    { value: '', label: '全部' },
    { value: 'failed', label: '失敗' },
    { value: 'skipped', label: '略過' },
    { value: 'pending', label: '發送中' },
    { value: 'sent', label: '已送達' },
];
const statusStyle = { sent: 'text-green-700', failed: 'text-red-600 font-bold', skipped: 'text-gray-500', pending: 'text-amber-600' };
const statusLabel = Object.fromEntries(statuses.map((s) => [s.value, s.label]));

const status = ref('');
const logs = ref([]);
const error = ref('');
const expanded = ref(null);

async function load() {
    const params = new URLSearchParams({ channel: 'line', ...(status.value && { status: status.value }) });
    logs.value = (await api('GET', `/api/admin/notifications?${params}`)).data;
}
onMounted(load);

async function resend(log) {
    error.value = '';
    try {
        const { data } = await api('POST', `/api/admin/notifications/${log.id}/resend`);
        Object.assign(log, data);
    } catch (e) {
        error.value = errorMessage(e);
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="mr-2 text-xl font-bold">LINE 通知紀錄</h1>
            <select v-model="status" class="input w-28" @change="load">
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
        </div>
        <p class="text-sm text-gray-500">每則通知也會同時寫入客戶的站內通知。「略過」表示客戶未綁定 LINE 或系統尚未設定金鑰。</p>
        <p v-if="error" class="alert-error">{{ error }}</p>

        <div class="overflow-x-auto rounded shadow">
            <table class="table">
                <thead><tr><th>時間</th><th>客戶</th><th>標題</th><th>狀態</th><th>原因</th><th></th></tr></thead>
                <tbody>
                    <template v-for="log in logs" :key="log.id">
                        <tr class="cursor-pointer hover:bg-blue-50" @click="expanded = expanded === log.id ? null : log.id">
                            <td class="whitespace-nowrap">{{ dateTime(log.created_at) }}</td>
                            <td>{{ log.customer?.name }}</td>
                            <td>{{ log.title }}</td>
                            <td :class="statusStyle[log.status]">{{ statusLabel[log.status] }}</td>
                            <td class="max-w-72 text-xs text-gray-600">{{ log.error }}</td>
                            <td class="text-right">
                                <button v-if="['failed', 'skipped'].includes(log.status)" class="btn" @click.stop="resend(log)">重送</button>
                            </td>
                        </tr>
                        <tr v-if="expanded === log.id">
                            <td colspan="6" class="bg-gray-50 text-sm whitespace-pre-line">{{ log.body }}</td>
                        </tr>
                    </template>
                    <tr v-if="!logs.length"><td colspan="6" class="py-8 text-center text-gray-400">沒有紀錄</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
