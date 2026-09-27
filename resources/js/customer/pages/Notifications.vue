<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../../shared/http';
import { loadMe, longDate } from '../store';

const notifications = ref(null);

onMounted(async () => {
    notifications.value = (await api('GET', '/api/customer/notifications')).data;
    if (notifications.value.some((n) => !n.read)) {
        await api('POST', '/api/customer/notifications/read-all');
        loadMe(true);
    }
});

const link = (n) => (n.related_type === 'order' ? `/orders/${n.related_id}` : null);
</script>

<template>
    <div class="px-4 pt-4">
        <p v-if="notifications && !notifications.length" class="cu-panel p-5 text-lg">目前沒有通知。</p>
        <ul class="space-y-3">
            <li v-for="n in notifications" :key="n.id" class="cu-panel p-5" :class="{ 'border-l-8 border-cu-pipe': !n.read }">
                <p class="text-lg text-cu-muted">{{ longDate(n.created_at) }}<span v-if="!n.read" class="ml-2 font-bold text-cu-pipe">新</span></p>
                <p class="mt-1 text-xl font-bold">{{ n.title }}</p>
                <p class="mt-2 text-lg whitespace-pre-line">{{ n.body }}</p>
                <RouterLink v-if="link(n)" :to="link(n)" class="cu-btn cu-btn-plain mt-3">看這張訂單</RouterLink>
            </li>
        </ul>
    </div>
</template>
