// The optional checkout fields (phone, order note, terms) shared by the store checkout and the selling page.
// A field that is switched off in admin is simply not in the form.
const el = <T extends HTMLElement>(form: HTMLElement, name: string) => form.querySelector<T>(`[name="${name}"]`);

export function extras(form: HTMLElement): Record<string, string | boolean> {
    const values: Record<string, string | boolean> = {};
    const phone = el<HTMLInputElement>(form, 'phone');
    const note = el<HTMLTextAreaElement>(form, 'customer_note');
    const terms = el<HTMLInputElement>(form, 'terms');
    if (phone) values.phone = phone.value.trim();
    if (note) values.customer_note = note.value.trim();
    if (terms) values.terms = terms.checked;
    return values;
}

export function extraProblems(form: HTMLElement): Record<string, string[]> {
    const problems: Record<string, string[]> = {};
    const phone = el<HTMLInputElement>(form, 'phone');
    const terms = el<HTMLInputElement>(form, 'terms');
    if (phone?.required && !phone.value.trim()) problems.phone = ['Enter your phone number.'];
    if (terms && !terms.checked) problems.terms = ['Tick the box to accept the terms.'];
    return problems;
}
