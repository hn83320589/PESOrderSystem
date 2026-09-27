<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { me, shop } from './store';

const route = useRoute();
const router = useRouter();
const title = computed(() => route.meta.title);
const showBack = computed(() => title.value && !route.meta.noBack);

function back() {
    // 從 LINE 直接開啟某頁時沒有上一頁，改回首頁
    if (window.history.state?.back) router.back();
    else router.push('/');
}
</script>

<template>
    <div class="mx-auto min-h-screen max-w-[560px] pb-10">
        <header v-if="me" class="flex min-h-16 items-center gap-2 bg-white px-3">
            <button v-if="showBack" class="flex min-h-12 min-w-12 items-center justify-center rounded-lg text-2xl text-cu-pipe active:bg-slate-100" aria-label="回上一頁" @click="back">
                ‹ <span class="ml-1 text-lg">返回</span>
            </button>
            <h1 class="flex-1 truncate text-xl font-bold outline-none" :class="{ 'pl-2': !showBack }" tabindex="-1" data-page-title>{{ title ?? shop.name }}</h1>
            <RouterLink v-if="route.path !== '/'" to="/" class="rounded-lg px-3 py-2 text-lg text-cu-pipe active:bg-slate-100">首頁</RouterLink>
        </header>
        <main>
            <RouterView />
        </main>
    </div>
</template>
