<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { errorMessage } from '../../shared/format';
import { login } from '../auth';

const router = useRouter();
const email = ref('');
const password = ref('');
const error = ref('');
const submitting = ref(false);

async function submit() {
    submitting.value = true;
    error.value = '';
    try {
        await login(email.value, password.value);
        router.push('/');
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <form class="mx-auto mt-24 max-w-sm space-y-4 rounded-lg bg-white p-6 shadow" @submit.prevent="submit">
        <h1 class="text-center text-xl font-bold">水電訂單管理後台</h1>
        <p v-if="error" class="alert-error">{{ error }}</p>
        <div>
            <label class="label" for="email">帳號（Email）</label>
            <input id="email" v-model="email" type="email" class="input" autocomplete="username" required />
        </div>
        <div>
            <label class="label" for="password">密碼</label>
            <input id="password" v-model="password" type="password" class="input" autocomplete="current-password" required />
        </div>
        <button class="btn btn-primary w-full py-2" :disabled="submitting">{{ submitting ? '登入中…' : '登入' }}</button>
    </form>
</template>
