import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import { ensureUser } from './auth';
import Inventory from './pages/Inventory.vue';
import Login from './pages/Login.vue';
import Products from './pages/Products.vue';
import Reservations from './pages/Reservations.vue';

const router = createRouter({
    history: createWebHistory('/admin/'),
    routes: [
        { path: '/', redirect: '/inventory' },
        { path: '/login', name: 'login', component: Login, meta: { guest: true } },
        { path: '/products', name: 'products', component: Products },
        { path: '/inventory', name: 'inventory', component: Inventory },
        { path: '/reservations', name: 'reservations', component: Reservations },
    ],
});

router.beforeEach(async (to) => {
    const user = await ensureUser();
    if (!to.meta.guest && !user) {
        return { name: 'login' };
    }
    if (to.meta.guest && user) {
        return '/';
    }
});

createApp(App).use(router).mount('#admin-app');
