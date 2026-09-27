/**
 * common.js の役割：JSON API通信とログアウトを共通化する。
 * 画面は表示を担当し、APIは処理とデータを担当するため、スマホアプリでも処理を再利用しやすい。
 */
'use strict';

/** 相対配置の違いを吸収し、アプリ内のURLを返す。 */
function appUrl(path) {
    const basePath = document.querySelector('meta[name="app-base-path"]').content;
    return `${basePath}/${path.replace(/^\//, '')}`;
}

/** 同じサイトのAPIへJSONを送り、日本語エラーと項目別エラーを呼び出し側へ渡す。 */
async function apiRequest(path, method = 'GET', data = null) {
    const headers = { Accept: 'application/json' };
    if (method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        // ブラウザーがCookieを自動送信するだけではCSRFを防げないため、トークンも送る。
        headers['X-CSRF-Token'] = document.querySelector('meta[name="csrf-token"]').content;
    }
    let response;
    try {
        response = await fetch(appUrl(path), {
            method,
            credentials: 'same-origin',
            headers,
            body: data === null ? null : JSON.stringify(data),
        });
    } catch (cause) {
        throw new Error('通信できませんでした。接続を確認して、もう一度お試しください。');
    }
    let result;
    try {
        result = await response.json();
    } catch (cause) {
        throw new Error('サーバーの応答を読み取れませんでした。時間をおいてお試しください。');
    }
    if (!response.ok || !result.success) {
        const error = new Error(result.message || '処理に失敗しました。');
        error.fields = result.errors || {};
        error.status = response.status;
        throw error;
    }
    if (result.data.csrf_token) {
        document.querySelector('meta[name="csrf-token"]').content = result.data.csrf_token;
    }
    return result.data;
}

/** CSRF保護されたPOSTでログアウトし、ログイン画面へ戻る。 */
async function handleLogout() {
    const button = document.getElementById('logout-button');
    const message = document.getElementById('logout-message');
    button.disabled = true;
    message.textContent = 'ログアウトしています…';
    try {
        await apiRequest('api/auth/logout.php', 'POST', {});
        window.location.assign(appUrl('login.php'));
    } catch (error) {
        if (error.status === 401) {
            window.location.assign(appUrl('login.php'));
            return;
        }
        // innerHTMLではなくtextContentを使い、APIの文字列をHTMLとして実行させない。
        message.textContent = error.message;
        button.disabled = false;
    }
}

const logoutButton = document.getElementById('logout-button');
if (logoutButton) {
    logoutButton.addEventListener('click', handleLogout);
}
