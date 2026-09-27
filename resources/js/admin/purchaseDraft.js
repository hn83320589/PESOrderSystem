import { ref } from 'vue';

// 叫貨建議 → 建立進貨單 之間傳遞的草稿（只在本頁面生命週期內）
export const purchaseDraft = ref(null); // { supplier_id, items: [{ variant_id, quantity, unit_cost }] }
