/** auth.js の役割：新規登録・ログインフォームをAPIへ送り、結果を画面へ反映する。 */
'use strict';

/** フォームをJSONへ変換する。成功時は遷移し、失敗時は該当欄に日本語で理由を表示する。 */
async function handleAuthSubmit(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('button[type="submit"]');
    const message = document.getElementById('form-message');
    const originalLabel = button.textContent;
    form.querySelectorAll('.field-error').forEach((field) => { field.textContent = ''; });
    form.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
    message.textContent = '';
    button.disabled = true;
    button.textContent = '送信しています…';
    const input = Object.fromEntries(new FormData(form).entries());
    try {
        const result = await apiRequest(`api/auth/${form.dataset.action}.php`, 'POST', input);
        const destination = form.dataset.action === 'register' ? 'login.php?registered=1' : (result.redirect || 'home.php');
        window.location.assign(appUrl(destination));
    } catch (error) {
        message.textContent = error.message;
        Object.entries(error.fields || {}).forEach(([name, text]) => {
            const fieldError = document.getElementById(`error-${name}`);
            const field = form.elements.namedItem(name);
            if (fieldError) fieldError.textContent = text;
            if (field) field.setAttribute('aria-invalid', 'true');
        });
        const firstInvalid = form.querySelector('[aria-invalid="true"]');
        (firstInvalid || message).focus();
        button.disabled = false;
        button.textContent = originalLabel;
    }
}

document.getElementById('auth-form').addEventListener('submit', handleAuthSubmit);
