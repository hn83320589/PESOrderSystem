import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import { ensureUser } from './auth';
import Customers from './pages/Customers.vue';
import Inventory from './pages/Inventory.vue';
import Login from './pages/Login.vue';
import MonthlyReport from './pages/MonthlyReport.vue';
import OrderDetail from './pages/OrderDetail.vue';
import OrderForm from './pages/OrderForm.vue';
import Orders from './pages/Orders.vue';
import Payments from './pages/Payments.vue';
import Products from './pages/Products.vue';
import Reservations from './pages/Reservations.vue';

const router = createRouter({
    history: createWebHistory('/admin/'),
    routes: [
        { path: '/', redirect: '/orders' },
        { path: '/login', name: 'login', component: Login, meta: { guest: true } },
        { path: '/orders', name: 'orders', component: Orders },
        { path: '/orders/new', name: 'order-create', component: OrderForm },
        { path: '/orders/:id', name: 'order-detail', component: OrderDetail },
        { path: '/orders/:id/edit', name: 'order-edit', component: OrderForm },
        { path: '/customers', name: 'customers', component: Customers },
        { path: '/payments', name: 'payments', component: Payments },
        { path: '/reports/monthly', name: 'monthly-report', component: MonthlyReport },
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
