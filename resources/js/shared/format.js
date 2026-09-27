export const money = (value) => `$${Number(value ?? 0).toLocaleString('zh-TW')}`;

export const dateTime = (value) =>
    value ? new Date(value).toLocaleString('zh-TW', { hour12: false, dateStyle: 'short', timeStyle: 'short' }) : '';

export const date = (value) => (value ? new Date(value).toLocaleDateString('zh-TW') : '');

// datetime-local input 需要的本地時間字串 YYYY-MM-DDTHH:mm
export const toLocalInput = (d) => {
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

// 將 API 錯誤轉成可顯示的文字（驗證錯誤取第一則）
export const errorMessage = (error) => {
    const errors = error?.data?.errors;
    if (errors) {
        return Object.values(errors)[0][0];
    }
    return error?.data?.message ?? error?.message ?? '發生錯誤，請稍後再試';
};
