import { computed, reactive, ref, watch } from 'vue';
import { api } from '../shared/http';

export const shop = window.__SHOP__ ?? { name: '', phone: null };

// ── 登入的客戶 ───────────────────────────────────
export const me = ref(null);
let meLoaded = false;

export async function loadMe(force = false) {
    if (!meLoaded || force) {
        meLoaded = true;
        try {
            me.value = (await api('GET', '/api/customer/me')).data;
        } catch {
            me.value = null;
        }
    }
    return me.value;
}

// ── 購物車（存在瀏覽器，關掉頁面再回來還在）──────────
const CART_KEY = 'pes-cart-v1';

function readSavedCart() {
    try {
        return JSON.parse(localStorage.getItem(CART_KEY)) ?? [];
    } catch {
        return [];
    }
}

// 每一項：{ variant_id, name, spec, unit, price, quantity, warning }
export const cart = reactive({ items: readSavedCart() });

watch(
    () => cart.items,
    (items) => {
        try {
            localStorage.setItem(CART_KEY, JSON.stringify(items));
        } catch {
            // 瀏覽器禁止儲存時（無痕模式等）購物車僅存在本頁
        }
    },
    { deep: true },
);

export const cartCount = computed(() => cart.items.length);
export const cartTotal = computed(() => cart.items.reduce((sum, i) => sum + i.price * i.quantity, 0));

export function quantityOf(variantId) {
    return cart.items.find((i) => i.variant_id === variantId)?.quantity ?? 0;
}

export function setQuantity(line, quantity) {
    const existing = cart.items.find((i) => i.variant_id === line.variant_id);
    if (quantity <= 0) {
        cart.items = cart.items.filter((i) => i.variant_id !== line.variant_id);
    } else if (existing) {
        existing.quantity = quantity;
    } else {
        cart.items.push({ ...line, quantity });
    }
}

export function replaceCart(lines) {
    cart.items = lines;
}

export function clearCart() {
    cart.items = [];
}

// ── 顯示用文字 ───────────────────────────────────
export const money = (value) => `$${Number(value ?? 0).toLocaleString('zh-TW')}`;

export const orderStatusText = {
    pending: '待店家確認',
    confirmed: '店家已確認',
    shipped: '已出貨',
    expired: '已取消',
};

export const longDate = (value) => {
    const d = new Date(value);
    return `${d.getMonth() + 1}月${d.getDate()}日`;
};

export const paymentOptions = [
    { value: 'bank_transfer', label: '匯款' },
    { value: 'cash_on_delivery', label: '付現金' },
    { value: 'check', label: '開支票' },
];

export const defaultPayment = () => (me.value?.billing_type === 'monthly' ? 'bank_transfer' : 'cash_on_delivery');
