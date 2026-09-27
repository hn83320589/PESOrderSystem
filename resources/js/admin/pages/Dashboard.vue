<script setup>
import { computed, onMounted, ref } from 'vue';
import { api } from '../../shared/http';
import { money } from '../../shared/format';
import BarList from '../components/charts/BarList.vue';
import ColumnChart from '../components/charts/ColumnChart.vue';

// 色票已以 dataviz 驗證腳本檢查（白底、淺色模式）：
// 類別 2 色 CVD ΔE 24.7；帳齡為單一藍色的順序色階，最淺階對白底 2.11:1
const SHIPPED_COLOR = '#2a78d6';
const COLLECTED_COLOR = '#eb6834';
const AGING_RAMP = ['#86b6ef', '#3987e5', '#1c5cab', '#0d366b'];

const ranges = [
    { months: 6, label: '近 6 個月' },
    { months: 12, label: '近 12 個月' },
];
const months = ref(12);
const data = ref(null);
const loading = ref(false);

async function load() {
    loading.value = true;
    try {
        data.value = (await api('GET', `/api/admin/dashboard?months=${months.value}`)).data;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

function selectRange(value) {
    months.value = value;
    load();
}

const monthLabel = (ym) => `${Number(ym.slice(5))}月`;
const trend = computed(() => ({
    categories: data.value.trend.map((t) => monthLabel(t.month)),
    series: [
        { name: '出貨金額', color: SHIPPED_COLOR, values: data.value.trend.map((t) => t.shipped) },
        { name: '收款金額', color: COLLECTED_COLOR, values: data.value.trend.map((t) => t.collected) },
    ],
}));
const aging = computed(() => data.value.aging.map((a, i) => ({ label: a.label, value: a.amount, color: AGING_RAMP[i], note: a.orders ? `${a.orders} 張` : '' })));
const products = computed(() => data.value.top_products.map((p) => ({ label: p.product_name, value: p.amount, note: `${p.quantity.toLocaleString('zh-TW')} ${p.unit}` })));
const overdue = computed(() => data.value.aging.slice(2).reduce((sum, a) => sum + a.amount, 0));
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="mr-2 text-xl font-bold">總覽</h1>
            <div class="flex rounded border bg-white p-0.5" role="group" aria-label="期間">
                <button v-for="r in ranges" :key="r.months" class="rounded px-3 py-1 text-sm" :aria-pressed="months === r.months"
                    :class="months === r.months ? 'bg-slate-800 text-white' : 'hover:bg-gray-100'" @click="selectRange(r.months)">{{ r.label }}</button>
            </div>
            <span class="text-sm text-gray-500">期間套用於趨勢與熱銷商品；應收、帳齡為目前狀態</span>
        </div>

        <!-- 重新載入時保留前一次畫面並淡化，不閃爍 -->
        <div v-if="data" class="space-y-4 transition-opacity" :class="{ 'opacity-50': loading }">
            <section class="grid gap-4 lg:grid-cols-[1.2fr_2fr]">
                <div class="rounded bg-white p-5 shadow">
                    <p class="text-sm text-gray-600">目前應收帳款（已出貨未收款）</p>
                    <p class="mt-1 text-5xl font-semibold">{{ money(data.summary.receivable) }}</p>
                    <p v-if="overdue" class="mt-2 text-sm text-red-700">⚠ 其中 {{ money(overdue) }} 已超過 60 天</p>
                    <RouterLink to="/payments" class="mt-3 inline-block text-sm text-blue-700 hover:underline">前往收款</RouterLink>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="rounded bg-white p-4 shadow">
                        <p class="text-sm text-gray-600">本月至今出貨</p>
                        <p class="mt-1 text-2xl font-semibold">{{ money(data.summary.shipped_this_month) }}</p>
                        <p class="mt-1 text-xs text-gray-500">上月全月 {{ money(data.summary.shipped_last_month) }}</p>
                    </div>
                    <div class="rounded bg-white p-4 shadow">
                        <p class="text-sm text-gray-600">本月至今收款</p>
                        <p class="mt-1 text-2xl font-semibold">{{ money(data.summary.collected_this_month) }}</p>
                    </div>
                    <RouterLink :to="{ name: 'orders', query: { status: 'pending' } }" class="rounded bg-white p-4 shadow hover:ring-2 hover:ring-blue-200">
                        <p class="text-sm text-gray-600">待確認訂單</p>
                        <p class="mt-1 text-2xl font-semibold">{{ data.summary.pending_orders }} 張</p>
                    </RouterLink>
                    <RouterLink to="/inventory?low=1" class="rounded bg-white p-4 shadow hover:ring-2 hover:ring-blue-200">
                        <p class="text-sm text-gray-600">低庫存規格</p>
                        <p class="mt-1 text-2xl font-semibold">{{ data.summary.low_stock_variants }} 項</p>
                    </RouterLink>
                </div>
            </section>

            <ColumnChart :title="`每月出貨與收款（${ranges.find((r) => r.months === months).label}）`" :categories="trend.categories" :series="trend.series" :format="money" />

            <section class="grid gap-4 lg:grid-cols-2">
                <BarList title="應收帳齡（出貨至今天數）" :rows="aging" :format="money" />
                <BarList :title="`熱銷商品（${ranges.find((r) => r.months === months).label}出貨金額）`" :rows="products" :format="money" empty-text="期間內沒有出貨" />
            </section>

            <figure class="rounded bg-white p-4 shadow">
                <figcaption class="mb-2 font-bold">應收最多的客戶</figcaption>
                <div class="overflow-x-auto">
                <table class="table whitespace-nowrap">
                    <thead>
                        <tr><th>客戶</th><th>結帳方式</th><th class="text-right">未收金額</th><th class="text-right">張數</th><th class="text-right">最久一張出貨至今</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in data.top_receivables" :key="r.customer_id">
                            <td class="font-medium">{{ r.customer_name }}</td>
                            <td>{{ r.billing_type_label }}</td>
                            <td class="text-right tabular-nums">{{ money(r.amount) }}</td>
                            <td class="text-right tabular-nums">{{ r.orders }}</td>
                            <td class="text-right tabular-nums" :class="{ 'font-bold text-red-700': r.oldest_days > 60 }">{{ r.oldest_days }} 天</td>
                            <td class="text-right"><RouterLink :to="{ name: 'payments', query: { customer_id: r.customer_id } }" class="text-sm text-blue-700 hover:underline">收款</RouterLink></td>
                        </tr>
                        <tr v-if="!data.top_receivables.length"><td colspan="6" class="py-6 text-center text-gray-400">目前沒有未收款</td></tr>
                    </tbody>
                </table>
                </div>
            </figure>
        </div>
    </div>
</template>
