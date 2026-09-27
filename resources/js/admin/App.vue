<script setup>
import { currentUser, logout } from './auth';

const links = [
    { to: '/orders', label: '訂單' },
    { to: '/payments', label: '收款' },
    { to: '/reports/monthly', label: '月結對帳' },
    { to: '/customers', label: '客戶' },
    { to: '/inventory', label: '庫存' },
    { to: '/products', label: '商品' },
    { to: '/reservations', label: '熟客預留' },
];
</script>

<template>
    <div class="min-h-screen">
        <header v-if="currentUser" class="flex flex-wrap items-center gap-4 bg-slate-800 px-4 py-2 text-white">
            <span class="font-bold">水電訂單管理</span>
            <nav class="flex gap-1">
                <RouterLink
                    v-for="link in links"
                    :key="link.to"
                    :to="link.to"
                    class="rounded px-3 py-1.5 hover:bg-slate-700"
                    active-class="bg-slate-600"
                >
                    {{ link.label }}
                </RouterLink>
            </nav>
            <span class="ml-auto text-sm text-slate-300">{{ currentUser.name }}</span>
            <button class="rounded px-3 py-1.5 text-sm hover:bg-slate-700" @click="logout">登出</button>
        </header>
        <main class="mx-auto max-w-7xl p-4">
            <RouterView />
        </main>
    </div>
</template>
