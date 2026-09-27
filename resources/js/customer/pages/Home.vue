<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../../shared/http';
import { cartCount, longDate, me, shop } from '../store';

const reservations = ref([]);

onMounted(async () => {
    reservations.value = (await api('GET', '/api/customer/reservations')).data;
});

const daysLeft = (expiresAt) => Math.max(0, Math.ceil((new Date(expiresAt) - Date.now()) / 86400000));
</script>

<template>
    <div class="space-y-5 px-4 pt-5">
        <p class="text-2xl font-bold">{{ me.contact_name || me.name }}，您好</p>

        <section v-if="reservations.length" class="cu-panel overflow-hidden border-2 border-amber-400">
            <h2 class="bg-cu-caution px-5 py-3 text-lg font-bold">店家幫您留的貨，請確認要不要</h2>
            <RouterLink v-for="r in reservations" :key="r.id" :to="`/reservations/${r.id}`"
                class="flex items-center gap-3 border-t border-cu-line px-5 py-4 active:bg-slate-50">
                <div class="flex-1">
                    <p class="text-lg font-bold">{{ r.product_name }} {{ r.spec }}</p>
                    <p class="text-lg text-cu-muted">{{ r.quantity }} {{ r.unit }}，{{ longDate(r.expires_at) }}前確認（剩 {{ daysLeft(r.expires_at) }} 天）</p>
                </div>
                <span class="text-lg font-bold text-cu-pipe">確認</span>
            </RouterLink>
        </section>

        <nav class="space-y-3">
            <RouterLink to="/reorder" class="cu-btn cu-btn-primary min-h-20 text-xl">照上次叫的貨再叫一次</RouterLink>
            <RouterLink to="/catalog" class="cu-btn cu-btn-plain min-h-20 text-xl">挑商品叫貨</RouterLink>
            <RouterLink v-if="cartCount" to="/review" class="cu-btn cu-btn-plain">還沒送出的叫貨單（{{ cartCount }} 項）</RouterLink>
            <RouterLink to="/orders" class="cu-btn cu-btn-plain">我叫過的貨</RouterLink>
            <RouterLink to="/notifications" class="cu-btn cu-btn-plain">
                店家通知<span v-if="me.unread_notifications" class="ml-2 rounded-full bg-cu-slip px-3 py-0.5 text-base text-white">{{ me.unread_notifications }} 則新的</span>
            </RouterLink>
        </nav>

        <a v-if="shop.phone" :href="`tel:${shop.phone}`" class="block py-4 text-center text-lg text-cu-pipe underline">有問題打給店家 {{ shop.phone }}</a>
    </div>
</template>
