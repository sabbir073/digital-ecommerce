/** Small fetch helper for the few admin calls that are not Inertia visits (media picker, uploads). */
function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    if (!response.ok) throw new Error(`Request failed (${response.status})`);
    return response.json() as Promise<T>;
}

export async function postForm<T>(url: string, body: FormData): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrfToken() },
    });
    if (response.status === 422) {
        const data = (await response.json()) as { errors?: Record<string, string[]> };
        throw new Error(Object.values(data.errors ?? {})[0]?.[0] ?? 'The upload was not accepted.');
    }
    if (response.status === 413) throw new Error('The file is larger than the server allows.');
    if (!response.ok) throw new Error(`Upload failed (${response.status}).`);
    return response.json() as Promise<T>;
}
