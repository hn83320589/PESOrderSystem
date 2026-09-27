import { ref } from 'vue';
import { api } from '../shared/http';

export const currentUser = ref(null);
let checked = false;

export async function ensureUser() {
    if (!checked) {
        checked = true;
        try {
            currentUser.value = (await api('GET', '/api/admin/me')).data;
        } catch {
            currentUser.value = null;
        }
    }
    return currentUser.value;
}

export async function login(email, password) {
    currentUser.value = (await api('POST', '/api/admin/login', { email, password })).data;
}

export async function logout() {
    await api('POST', '/api/admin/logout');
    currentUser.value = null;
    // session 已重建，重新載入以取得新的 CSRF token
    window.location.href = '/admin/login';
}
