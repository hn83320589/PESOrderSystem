<script setup>
import { computed, onMounted, ref } from 'vue';
import { api } from '../../shared/http';
import QtyStepper from '../components/QtyStepper.vue';
import { cartCount, cartTotal, money, quantityOf, setQuantity } from '../store';

const products = ref([]);
const keyword = ref('');
const category = ref('');
const openProduct = ref(null);

onMounted(async () => {
    products.value = (await api('GET', '/api/customer/products')).data;
});

const categories = computed(() => [...new Set(products.value.map((p) => p.category).filter(Boolean))]);
const visible = computed(() => {
    const k = keyword.value.trim();
    return products.value.filter((p) =>
        (!category.value || p.category === category.value)
        && (!k || p.name.includes(k) || p.variants.some((v) => v.spec.includes(k))));
});

const stockText = { in_stock: '有貨', low: '剩不多', out: '缺貨' };
const stockClass = { in_stock: 'text-green-800', low: 'text-amber-700', out: 'text-cu-slip' };

function update(product, variant, quantity) {
    setQuantity({ variant_id: variant.id, name: product.name, spec: variant.spec, unit: product.unit, price: variant.price, warning: '' }, quantity);
}
const pickedIn = (product) => product.variants.filter((v) => quantityOf(v.id) > 0).length;
</script>

<template>
    <div class="px-4 pt-4" :class="{ 'pb-36': cartCount }">
        <input v-model="keyword" type="search" class="cu-input" placeholder="找商品，例如：水管、開關" aria-label="搜尋商品" />
        <div class="mt-3 flex gap-2" role="group" aria-label="商品分類">
            <button v-for="c in ['', ...categories]" :key="c" :aria-pressed="category === c"
                class="min-h-12 flex-1 rounded-lg border-2 text-lg font-bold"
                :class="category === c ? 'border-cu-pipe bg-cu-pipe text-white' : 'border-cu-line bg-white'"
                @click="category = c">{{ c ? `${c}類` : '全部' }}</button>
        </div>

        <ul class="cu-panel mt-4 divide-y divide-cu-line">
            <li v-for="product in visible" :key="product.id">
                <button class="flex min-h-16 w-full items-center justify-between gap-3 px-5 py-3 text-left" :aria-expanded="openProduct === product.id" :aria-controls="`specs-${product.id}`"
                    @click="openProduct = openProduct === product.id ? null : product.id">
                    <span class="text-xl font-bold">{{ product.name }}</span>
                    <span class="shrink-0 text-lg text-cu-pipe">
                        <template v-if="pickedIn(product)">已選 {{ pickedIn(product) }} 種　</template>{{ openProduct === product.id ? '收起' : '選規格' }}
                    </span>
                </button>
                <ul v-if="openProduct === product.id" :id="`specs-${product.id}`" class="space-y-4 bg-slate-50 px-5 py-4">
                    <li v-for="v in product.variants" :key="v.id" class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-xl font-bold">{{ v.spec }}</p>
                            <p class="text-lg"><span class="cu-num">{{ money(v.price) }}</span> / {{ product.unit }}　<span :class="stockClass[v.stock_status]">{{ stockText[v.stock_status] }}</span></p>
                        </div>
                        <QtyStepper :model-value="quantityOf(v.id)" :label="`${product.name} ${v.spec}`" @update:model-value="update(product, v, $event)" />
                    </li>
                </ul>
            </li>
            <li v-if="!visible.length" class="px-5 py-8 text-center text-lg text-cu-muted">找不到「{{ keyword }}」，可以換個字試試，或打電話給店家。</li>
        </ul>

        <div v-if="cartCount" class="fixed inset-x-0 bottom-0 border-t-2 border-cu-line bg-white px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
            <div class="mx-auto max-w-[560px]">
                <p class="mb-2 text-lg" aria-live="polite">已選 {{ cartCount }} 項，約 <strong class="cu-num">{{ money(cartTotal) }}</strong></p>
                <RouterLink to="/review" class="cu-btn cu-btn-primary">下一步：看叫貨單</RouterLink>
            </div>
        </div>
    </div>
</template>
