// 同網域 session 認證：帶上 cookie 與 CSRF token
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content;

export class ApiError extends Error {
    constructor(status, data) {
        super(data?.message ?? `HTTP ${status}`);
        this.status = status;
        this.data = data;
    }
}

export async function api(method, url, body) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    const data = response.status === 204 ? null : await response.json().catch(() => null);
    if (!response.ok) {
        throw new ApiError(response.status, data);
    }
    return data;
}
