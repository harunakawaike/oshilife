/**
 * oshis.js の役割：推し検索・作成・編集・登録解除とメンバー追加の画面操作をまとめる。
 * APIが返した名前や絵文字はtextContentで表示し、HTMLとして解釈させない。
 */
'use strict';
const oshiTypeLabels = { group: 'グループ', solo: 'ソロアーティスト', actor: '俳優 / タレント', other: 'その他' };
let searchQuery = '';
let searchPage = 1;
let searchVersion = 0;

/** 要素と文字列を安全に作る共通関数。innerHTMLへ入力値を埋め込まない。 */
function textElement(tag, className, text = '') {
    const element = document.createElement(tag);
    element.className = className;
    element.textContent = text;
    return element;
}

/** 本人の一覧では解除、検索結果では追加・登録済みを表示する。 */
function oshiCard(oshi, isMine) {
    const card = textElement('article', 'oshi-card');
    card.dataset.oshiId = String(oshi.id);
    const emoji = textElement('span', 'oshi-card-emoji', oshi.emoji);
    emoji.setAttribute('aria-hidden', 'true');
    const content = textElement('div', 'oshi-card-content');
    content.append(textElement('h3', '', oshi.name), textElement('p', 'type-label', oshiTypeLabels[oshi.oshi_type] || oshi.oshi_type));
    const actions = textElement('div', 'oshi-card-actions');
    const link = textElement('a', '', '詳細を見る →');
    link.href = appUrl(`oshi_detail.php?id=${oshi.id}`);
    const followed = Number(oshi.is_followed) === 1;
    const button = textElement('button', isMine ? 'remove-button' : 'button secondary small', isMine ? '登録解除' : (followed ? '登録済み' : '＋ 追加'));
    button.type = 'button';
    button.dataset.oshiId = String(oshi.id);
    button.dataset.oshiName = oshi.name;
    button.dataset.action = isMine ? 'unfollow' : 'follow';
    button.disabled = !isMine && followed;
    actions.append(link, button);
    content.append(actions);
    card.append(emoji, content);
    return card;
}

/** 本人の一覧だけをAPIで更新する。検索結果の共有マスターとは区別して表示する。 */
async function loadMyOshis() {
    const container = document.getElementById('my-oshis');
    if (!container) return;
    const data = await apiRequest('api/oshis/my.php');
    container.replaceChildren();
    if (data.oshis.length === 0) {
        const empty = textElement('div', 'empty-state');
        empty.append(textElement('h3', '', 'まだ、推しが登録されていません。'), textElement('p', '', '下の検索から、あなたの好きな人やグループを探してみましょう。'));
        container.append(empty);
    }
    data.oshis.forEach((oshi) => container.append(oshiCard(oshi, true)));
}

/** 検索をページ単位で表示する。古い検索の応答が新しい結果を上書きしないよう番号で照合する。 */
async function searchOshis(append = false) {
    const version = ++searchVersion;
    const page = append ? searchPage + 1 : 1;
    const status = document.getElementById('search-status');
    const more = document.getElementById('search-more');
    const prompt = document.getElementById('create-prompt');
    status.textContent = '検索しています…';
    more.disabled = true;
    prompt.hidden = true;
    try {
        const data = await apiRequest(`api/oshis/search.php?q=${encodeURIComponent(searchQuery)}&page=${page}`);
        if (version !== searchVersion) return;
        const results = document.getElementById('search-results');
        if (!append) results.replaceChildren();
        data.oshis.forEach((oshi) => results.append(oshiCard(oshi, false)));
        searchPage = page;
        status.textContent = results.children.length ? `${results.children.length}件を表示しています。` : '一致する推しが見つかりませんでした。新しく登録できます。';
        more.hidden = !data.has_more;
        prompt.hidden = false;
    } catch (error) {
        if (version === searchVersion) {
            status.textContent = error.message;
            more.hidden = true;
        }
    } finally {
        if (version === searchVersion) more.disabled = false;
    }
}

/** 解除は確認してから送信。user_idは送らず、サーバーがセッションで本人を決める。 */
async function handleOshiAction(event) {
    const button = event.target.closest('button[data-action]');
    if (!button || button.disabled) return;
    const action = button.dataset.action;
    if (!['follow', 'unfollow'].includes(action)) return;
    if (action === 'unfollow' && !window.confirm(`「${button.dataset.oshiName}」を自分の推しから解除しますか？共有情報は削除されません。`)) return;
    const message = document.getElementById('oshi-message');
    button.disabled = true;
    try {
        await apiRequest(`api/oshis/${action}.php`, 'POST', { oshi_id: Number(button.dataset.oshiId) });
        message.textContent = action === 'follow' ? '自分の推しに追加しました。' : '自分の推しから解除しました。共有情報は残っています。';
        await loadMyOshis();
        if (searchQuery) await searchOshis();
    } catch (error) {
        message.textContent = error.message;
        button.disabled = false;
    }
}

/** 前回エラーを消し、今回のエラーを項目ごとに関連付ける。 */
function showOshiFormErrors(form, prefix, error = null) {
    form.querySelectorAll('.field-error').forEach((element) => { element.textContent = ''; });
    form.querySelectorAll('[aria-invalid]').forEach((element) => element.removeAttribute('aria-invalid'));
    if (!error) return;
    Object.entries(error.fields || {}).forEach(([name, text]) => {
        const target = document.getElementById(`${prefix}-error-${name}`);
        const input = form.elements.namedItem(name);
        if (target) target.textContent = text;
        if (input instanceof HTMLElement) input.setAttribute('aria-invalid', 'true');
    });
}

/** 推し作成後は詳細へ移動する。作成と自分への登録はAPIで一度に行う。 */
async function handleOshiCreate(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('button[type="submit"]');
    const message = document.getElementById('create-message');
    showOshiFormErrors(form, 'oshi');
    button.disabled = true;
    message.textContent = '登録しています…';
    try {
        const data = await apiRequest('api/oshis/create.php', 'POST', Object.fromEntries(new FormData(form)));
        window.location.assign(appUrl(`oshi_detail.php?id=${data.oshi.id}`));
    } catch (error) {
        showOshiFormErrors(form, 'oshi', error);
        message.textContent = error.message;
        (form.querySelector('[aria-invalid]') || message).focus();
        button.disabled = false;
    }
}

/** 推し情報を更新し、サーバーから最新の詳細を読み直して表示を揃える。 */
async function handleOshiEdit(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const id = Number(document.getElementById('oshi-detail').dataset.oshiId);
    const submit = form.querySelector('button[type="submit"]');
    const cancel = document.getElementById('oshi-edit-cancel');
    const message = document.getElementById('edit-message');
    const originalLabel = submit.textContent;
    showOshiFormErrors(form, 'edit');
    submit.disabled = true;
    cancel.disabled = true;
    submit.textContent = '保存しています…';
    message.textContent = '';
    try {
        const input = Object.fromEntries(new FormData(form));
        await apiRequest('api/oshis/update.php', 'POST', { ...input, oshi_id: id });
        window.location.assign(appUrl(`oshi_detail.php?id=${id}&updated=1`));
    } catch (error) {
        showOshiFormErrors(form, 'edit', error);
        message.textContent = error.message;
        (form.querySelector('[aria-invalid]') || message).focus();
        submit.disabled = false;
        cancel.disabled = false;
        submit.textContent = originalLabel;
    }
}

/** 保存前の変更を捨て、編集を開いたときの値に戻す。API通信は行わない。 */
function cancelOshiEdit() {
    const form = document.getElementById('oshi-edit');
    form.reset();
    showOshiFormErrors(form, 'edit');
    document.getElementById('edit-message').textContent = '';
    const panel = document.getElementById('oshi-edit-panel');
    panel.open = false;
    panel.querySelector('summary').focus();
}

/** 最新メンバーを読み、丸ではなくハート絵文字で表示する。 */
async function loadMembers(id) {
    const data = await apiRequest(`api/oshis/members.php?id=${id}`);
    const list = document.getElementById('oshi-members');
    list.replaceChildren();
    data.members.forEach((member) => {
        const card = textElement('article', 'member-card');
        const text = textElement('div', '');
        text.append(textElement('h3', '', member.name), textElement('p', '', `${member.color_name}  ${member.hex_color}`));
        card.append(textElement('span', 'member-heart', member.heart_emoji), text);
        list.append(card);
    });
}

/** ハート選択も含めて保存し、一覧へ即座に反映する。 */
async function handleMemberCreate(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const id = Number(document.getElementById('oshi-detail').dataset.oshiId);
    const button = form.querySelector('button[type="submit"]');
    const message = document.getElementById('member-message');
    showOshiFormErrors(form, 'member');
    message.textContent = '追加しています…';
    button.disabled = true;
    try {
        const input = Object.fromEntries(new FormData(form));
        await apiRequest('api/oshis/members/create.php', 'POST', { ...input, oshi_id: id });
        form.reset();
        await loadMembers(id);
        message.textContent = 'メンバーを追加しました。';
    } catch (error) {
        showOshiFormErrors(form, 'member', error);
        message.textContent = error.message;
        (form.querySelector('[aria-invalid]') || message).focus();
    } finally {
        button.disabled = false;
    }
}

/** 詳細ページの登録を切り替える。解除時には確認ダイアログを出す。 */
async function handleDetailFollow(event) {
    const button = event.currentTarget;
    const followed = button.dataset.followed === '1';
    if (followed && !window.confirm('自分の推しから解除しますか？共有情報は削除されません。')) return;
    const id = Number(document.getElementById('oshi-detail').dataset.oshiId);
    const message = document.getElementById('oshi-message');
    button.disabled = true;
    try {
        await apiRequest(`api/oshis/${followed ? 'unfollow' : 'follow'}.php`, 'POST', { oshi_id: id });
        button.dataset.followed = followed ? '0' : '1';
        button.textContent = followed ? '＋ 自分の推しに追加' : '自分の推しから解除';
        message.textContent = followed ? '自分の推しから解除しました。' : '自分の推しに追加しました。';
    } catch (error) {
        message.textContent = error.message;
    } finally {
        button.disabled = false;
    }
}

/** 各ページに存在する部品だけを初期化する。 */
function initializeOshiPage() {
    const search = document.getElementById('oshi-search');
    if (search) {
        loadMyOshis().catch((error) => { document.getElementById('my-oshis').textContent = error.message; });
        search.addEventListener('submit', (event) => {
            event.preventDefault();
            searchQuery = document.getElementById('oshi-query').value.trim();
            if (!searchQuery) return;
            searchOshis();
        });
        document.getElementById('search-more').addEventListener('click', () => searchOshis(true));
        document.getElementById('show-create').addEventListener('click', (event) => {
            document.getElementById('create-section').hidden = false;
            event.currentTarget.setAttribute('aria-expanded', 'true');
            const name = document.getElementById('oshi-name');
            if (!name.value) name.value = searchQuery;
            name.focus();
        });
        document.getElementById('oshi-create').addEventListener('submit', handleOshiCreate);
        document.getElementById('my-oshis').addEventListener('click', handleOshiAction);
        document.getElementById('search-results').addEventListener('click', handleOshiAction);
    }
    document.getElementById('oshi-edit')?.addEventListener('submit', handleOshiEdit);
    document.getElementById('oshi-edit-cancel')?.addEventListener('click', cancelOshiEdit);
    document.getElementById('member-create')?.addEventListener('submit', handleMemberCreate);
    document.getElementById('detail-follow')?.addEventListener('click', handleDetailFollow);
    document.querySelectorAll('[data-color]').forEach((element) => {
        // 指定プロパティと色形式を限定して、任意のCSSを注入させない。
        if (/^#[0-9a-f]{6}$/i.test(element.dataset.color)) element.style.backgroundColor = element.dataset.color;
    });
}
initializeOshiPage();
