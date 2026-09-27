import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import { onUnauthorized } from '../shared/http';
import { nextTick } from 'vue';
import { loadMe, me, shop } from './store';
import Catalog from './pages/Catalog.vue';
import Done from './pages/Done.vue';
import Home from './pages/Home.vue';
import Login from './pages/Login.vue';
import Notifications from './pages/Notifications.vue';
import OrderDetail from './pages/OrderDetail.vue';
import Orders from './pages/Orders.vue';
import Reorder from './pages/Reorder.vue';
import ReservationConfirm from './pages/ReservationConfirm.vue';
import Review from './pages/Review.vue';

const router = createRouter({
    history: createWebHistory('/'),
    routes: [
        { path: '/', component: Home, meta: { title: null } },
        { path: '/login', component: Login, meta: { guest: true } },
        { path: '/reorder', component: Reorder, meta: { title: '照上次叫的貨' } },
        { path: '/catalog', component: Catalog, meta: { title: '挑商品叫貨' } },
        { path: '/review', component: Review, meta: { title: '確認叫貨單' } },
        { path: '/done/:id', component: Done, meta: { title: '訂單已送出', noBack: true } },
        { path: '/orders', component: Orders, meta: { title: '我叫過的貨' } },
        { path: '/orders/:id', component: OrderDetail, meta: { title: '訂單內容' } },
        { path: '/reservations/:id', component: ReservationConfirm, meta: { title: '確認預留的貨' } },
        { path: '/notifications', component: Notifications, meta: { title: '店家通知' } },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
    scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach(async (to) => {
    const customer = await loadMe();
    if (!to.meta.guest && !customer) {
        return { path: '/login', query: to.query.error ? { error: to.query.error } : {} };
    }
    if (to.meta.guest && customer) {
        return '/';
    }
});

// 單頁應用換頁時瀏覽器不會自動處理：更新分頁標題，並把焦點移到新頁面的標題，讓螢幕閱讀器知道換頁了
let firstNavigation = true;
router.afterEach((to) => {
    document.title = to.meta.title ? `${to.meta.title}｜${shop.name}` : `${shop.name}｜線上叫貨`;
    if (firstNavigation) {
        firstNavigation = false;
        return;
    }
    nextTick(() => document.querySelector('[data-page-title]')?.focus({ preventScroll: true }));
});

// 原本已登入、之後被登出（例如店家解除 LINE 綁定）：導回登入頁並說明
onUnauthorized(() => {
    if (me.value) {
        me.value = null;
        window.location.href = '/login?error=session';
    }
});

createApp(App).use(router).mount('#customer-app');
