<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { shop } from '../store';

const route = useRoute();

// 錯誤代碼來自 LINE 登入流程（LineAuthController）
const messages = {
    not_bound: '這個 LINE 帳號還沒有開通線上叫貨。',
    bind_invalid: '這個開通連結已經用過或過期了。',
    line_in_use: '這個 LINE 帳號已經開通在別家店名下。',
    cancelled: '剛剛沒有完成 LINE 登入，請再按一次。',
    state: '登入逾時，請再按一次。',
    line_error: 'LINE 暫時連不上，請稍後再試。',
    line_not_configured: '線上叫貨還在準備中。',
};
const error = computed(() => messages[route.query.error]);
const needsShop = computed(() => ['not_bound', 'bind_invalid', 'line_in_use', 'line_not_configured'].includes(route.query.error));
</script>

<template>
    <div class="flex min-h-screen flex-col justify-center gap-8 px-5 py-10">
        <div>
            <p class="text-lg text-cu-muted">{{ shop.name }}</p>
            <h1 class="mt-1 text-3xl font-bold">線上叫貨</h1>
        </div>

        <div v-if="error" class="cu-panel border-l-8 border-cu-slip p-5" role="alert">
            <p class="text-lg font-bold">{{ error }}</p>
            <p v-if="needsShop" class="mt-2 text-lg">請打電話給店家幫您處理。</p>
            <a v-if="needsShop && shop.phone" :href="`tel:${shop.phone}`" class="cu-btn cu-btn-plain mt-4">打電話給店家 {{ shop.phone }}</a>
        </div>

        <a href="/auth/line" class="cu-btn bg-cu-line-green text-white">用 LINE 登入</a>
        <p class="text-lg text-cu-muted">第一次使用，請點店家用 LINE 傳給您的開通連結。</p>
    </div>
</template>
