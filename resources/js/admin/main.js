import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import { onUnauthorized } from '../shared/http';
import { currentUser, ensureUser } from './auth';
import Customers from './pages/Customers.vue';
import Dashboard from './pages/Dashboard.vue';
import Inventory from './pages/Inventory.vue';
import Login from './pages/Login.vue';
import MonthlyReport from './pages/MonthlyReport.vue';
import Notifications from './pages/Notifications.vue';
import OrderDetail from './pages/OrderDetail.vue';
import OrderForm from './pages/OrderForm.vue';
import Orders from './pages/Orders.vue';
import Payments from './pages/Payments.vue';
import Products from './pages/Products.vue';
import PurchaseForm from './pages/PurchaseForm.vue';
import Purchases from './pages/Purchases.vue';
import ReorderSuggestions from './pages/ReorderSuggestions.vue';
import Reservations from './pages/Reservations.vue';
import Suppliers from './pages/Suppliers.vue';

const router = createRouter({
    history: createWebHistory('/admin/'),
    routes: [
        { path: '/', redirect: '/dashboard' },
        { path: '/dashboard', name: 'dashboard', component: Dashboard },
        { path: '/login', name: 'login', component: Login, meta: { guest: true } },
        { path: '/orders', name: 'orders', component: Orders },
        { path: '/orders/new', name: 'order-create', component: OrderForm },
        { path: '/orders/:id', name: 'order-detail', component: OrderDetail },
        { path: '/orders/:id/edit', name: 'order-edit', component: OrderForm },
        { path: '/customers', name: 'customers', component: Customers },
        { path: '/payments', name: 'payments', component: Payments },
        { path: '/reports/monthly', name: 'monthly-report', component: MonthlyReport },
        { path: '/products', name: 'products', component: Products },
        { path: '/purchases', name: 'purchases', component: Purchases },
        { path: '/purchases/new', name: 'purchase-create', component: PurchaseForm },
        { path: '/purchases/suggestions', name: 'reorder-suggestions', component: ReorderSuggestions },
        { path: '/suppliers', name: 'suppliers', component: Suppliers },
        { path: '/notifications', name: 'notifications', component: Notifications },
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

// 使用中 session 過期：回登入頁，避免停在空白畫面
onUnauthorized(() => {
    if (currentUser.value) {
        currentUser.value = null;
        window.location.href = '/admin/login';
    }
});

createApp(App).use(router).mount('#admin-app');
