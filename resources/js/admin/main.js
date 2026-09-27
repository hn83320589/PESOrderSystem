import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import Dashboard from './pages/Dashboard.vue';

const router = createRouter({
    history: createWebHistory('/admin/'),
    routes: [{ path: '/', name: 'dashboard', component: Dashboard }],
});

createApp(App).use(router).mount('#admin-app');
