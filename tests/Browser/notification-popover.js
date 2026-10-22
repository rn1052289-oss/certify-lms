/**
 * ログイン済み実画面で evaluate_script の sourcePath から実行する回帰確認。
 * 実 DOM / app.js のイベント配線を使い、通知 API の fetch だけを一時置換する。
 * DB は更新しない。単一既読の成功遷移は別途、実操作で確認する。
 */
async () => {
    const root = document.querySelector('[data-notification-popover-root]');
    const assert = (condition, message) => {
        if (!condition) throw new Error(message);
    };
    assert(root, '受講生またはコーチでログインした通知ベルのある画面が必要です。');

    const find = (name) => root.querySelector(`[data-notification-popover-${name}]`);
    const trigger = find('trigger');
    const panel = find('panel');
    const items = find('items');
    const empty = find('empty');
    const loading = find('loading');
    const markAll = find('mark-all');
    const all = root.querySelector('[data-notification-popover-tab="all"]');
    const unread = root.querySelector('[data-notification-popover-tab="unread"]');
    const originalFetch = window.fetch;
    const originalUrl = window.location.href;
    const requests = [];
    const results = [];
    const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
    const flush = async () => { await tick(); await tick(); };
    const waitFor = async (condition, message) => {
        const deadline = Date.now() + 3000;
        while (!condition()) {
            assert(Date.now() < deadline, message);
            await tick();
        }
    };
    const test = async (name, action) => {
        try {
            await action();
            results.push({ name, status: 'PASS' });
        } catch (error) {
            throw new Error(`${name}: ${error.message}`, { cause: error });
        }
    };
    const next = async (method = 'GET') => {
        await waitFor(() => requests.some((request) => !request.taken), `${method} の通知 API が呼ばれません。`);
        const request = requests.find((entry) => !entry.taken);
        request.taken = true;
        assert(request.method === method, `期待 ${method} / 実際 ${request.method}`);
        return request;
    };
    const respond = async (request, payload, status = 200) => {
        assert(!request.responded, '同じリクエストへ二重に応答しています。');
        request.responded = true;
        request.resolve(new Response(JSON.stringify(payload), {
            status,
            headers: { 'Content-Type': 'application/json' },
        }));
        await flush();
    };
    const notification = (id = 'browser-fixture', title = '通知', unread = true) => ({
        id, title, message: '通知本文', created_relative: '1分前', unread,
    });
    const payload = (count, notifications = [notification()]) => ({ notifications, unread_count: count });
    const countIs = (count) => {
        const badge = find('badge');
        assert(badge.textContent === (count > 99 ? '99+' : String(count)), 'ベルの件数表示が不正です。');
        assert(badge.classList.contains('hidden') === (count === 0), '0件時のバッジ表示が不正です。');
        assert(find('unread-count').textContent === String(count), '未読タブが実総数ではありません。');
        assert(trigger.getAttribute('aria-label') === `通知 (${count} 件未読)`, 'ARIA が実総数ではありません。');
    };
    const load = async (count, notifications = [notification()], button = all) => {
        button.click();
        const request = await next();
        await respond(request, payload(count, notifications));
        return request;
    };
    const errorIsVisible = () => {
        assert(!empty.classList.contains('hidden') && empty.getAttribute('role') === 'alert', '通信失敗のエラーが表示されません。');
        assert(loading.classList.contains('hidden'), '通信失敗後も読み込み中です。');
    };

    assert(!markAll.disabled, '既存の更新処理が完了してから実行してください。');
    window.fetch = (input, options = {}) => {
        const url = new URL(typeof input === 'string' ? input : input.url, window.location.href);
        if (!url.pathname.startsWith('/api/v1/notifications')) return originalFetch.call(window, input, options);
        return new Promise((resolve) => {
            requests.push({ url, options, method: options.method ?? 'GET', resolve, taken: false, responded: false });
        });
    };

    try {
        if (trigger.getAttribute('aria-expanded') === 'true') trigger.click();
        trigger.click();
        await respond(await next(), payload(0, []));

        await test('0 / 99 / 100 件のバッジ・実数 ARIA・空表示', async () => {
            countIs(0);
            assert(items.children.length === 0 && !empty.classList.contains('hidden'), '空通知の表示が不正です。');
            await load(99);
            countIs(99);
            await load(100);
            countIs(100);
        });

        await test('HTML 文字列を textContent で描画し line-clamp-2 を維持', async () => {
            const text = '<img src=x onerror="window.__notificationInjected=true">';
            const record = { ...notification('html', text), message: text, created_relative: text };
            await load(1, [record]);
            for (const name of ['row-title', 'row-message', 'row-time']) {
                assert(find(name).textContent === text && find(name).children.length === 0, `${name} が HTML として解釈されています。`);
            }
            assert(find('row-message').classList.contains('line-clamp-2'), '本文の2行省略が失われています。');
        });

        await test('未読タブの query / GET の Cookie・JSON 設定', async () => {
            const request = await load(1, [notification()], unread);
            assert(request.url.searchParams.get('tab') === 'unread', '未読タブの query が不正です。');
            assert(request.options.credentials === 'same-origin', 'Cookie を送る設定がありません。');
            assert(request.options.cache === 'no-store', '再取得時にキャッシュを使用しています。');
            assert(new Headers(request.options.headers).get('Accept') === 'application/json', 'JSON を要求していません。');
            assert(unread.getAttribute('aria-selected') === 'true' && all.getAttribute('aria-selected') === 'false', 'タブ選択表示が不正です。');
        });

        await test('GET 失敗と再試行', async () => {
            all.click();
            await respond(await next(), { message: 'failure' }, 503);
            errorIsVisible();
            countIs(1);
            await load(2);
            countIs(2);
            assert(empty.classList.contains('hidden') && !empty.hasAttribute('role'), '再試行成功後もエラーが残っています。');
        });

        await test('単一 POST 失敗は遷移・件数変更なし / 二重送信防止 / CSRF ヘッダ', async () => {
            const row = find('row');
            row.click();
            const request = await next('POST');
            assert(request.url.pathname.endsWith('/browser-fixture/read'), '単一既読の URL が不正です。');
            assert(request.options.credentials === 'same-origin', 'POST が Cookie を送信しません。');
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            assert(csrf && new Headers(request.options.headers).get('X-CSRF-TOKEN') === csrf, 'ページの CSRF トークンが送信されません。');
            row.click();
            await flush();
            assert(!requests.some((entry) => !entry.taken), '単一既読が二重送信されました。');
            await respond(request, { message: 'failure' }, 419);
            countIs(2);
            errorIsVisible();
            assert(window.location.href === originalUrl, '失敗した単一既読が遷移しました。');
            assert(!markAll.disabled && !all.disabled && !unread.disabled, '失敗後に操作が復帰しません。');
        });

        await test('全件既読の失敗と再試行 / 成功後の再取得 / 二重送信防止', async () => {
            await load(100);
            markAll.click();
            const failed = await next('POST');
            assert(failed.url.pathname.endsWith('/read-all'), '全件既読の URL が不正です。');
            await respond(failed, { message: 'failure' }, 500);
            countIs(100);
            errorIsVisible();
            assert(items.children.length === 1, '失敗した更新が通知行を削除しました。');
            markAll.click();
            const succeeded = await next('POST');
            markAll.click();
            all.click();
            unread.click();
            await flush();
            assert(markAll.disabled && all.disabled && unread.disabled, '更新中に操作がロックされません。');
            assert(!requests.some((entry) => !entry.taken), '更新中に二重送信またはタブ取得が発生しました。');
            await respond(succeeded, { unread_count: 0 });
            const refresh = await next();
            assert(markAll.disabled, '更新後の再取得より先にロックが解除されました。');
            await respond(refresh, payload(0, [notification('read', '既読', false)]));
            countIs(0);
            assert(!markAll.disabled && find('row-dot').classList.contains('invisible'), '成功後の既読表示が不正です。');
        });

        await test('タブ取得の古い遅延 GET 応答を無視', async () => {
            all.click();
            const old = await next();
            unread.click();
            const current = await next();
            await respond(current, payload(3, [notification('new', '新しいタブ')]));
            await respond(old, payload(99, [notification('old', '古いタブ')]));
            countIs(3);
            assert(find('row-title').textContent === '新しいタブ', '古い GET が最新タブを上書きしました。');
            assert(loading.classList.contains('hidden'), '遅延応答後も読み込み中です。');
        });

        await test('更新開始前の遅延 GET が全件既読結果を上書きしない', async () => {
            all.click();
            const old = await next();
            unread.click();
            const olderThanMutation = await next();
            markAll.click();
            const mutation = await next('POST');
            await respond(old, payload(99, [notification('during', '更新処理中の古い取得')]));
            countIs(3);
            assert(items.children.length === 0 && markAll.disabled, '更新処理中に古い GET が表示されました。');
            await respond(mutation, { unread_count: 0 });
            const refresh = await next();
            await respond(refresh, payload(0, [notification('mutated', '更新後', false)]));
            await respond(olderThanMutation, payload(100, [notification('stale', '更新前')]));
            countIs(0);
            assert(find('row-title').textContent === '更新後', '更新前の GET が既読結果を上書きしました。');
        });

        await test('Esc / 外側クリック / 再開閉による再取得', async () => {
            root.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
            assert(trigger.getAttribute('aria-expanded') === 'false' && panel.classList.contains('hidden'), 'Esc で閉じません。');
            assert(document.activeElement === trigger, 'Esc 後にベルへフォーカスが戻りません。');
            trigger.click();
            await respond(await next(), payload(4));
            countIs(4);
            document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));
            assert(trigger.getAttribute('aria-expanded') === 'false' && panel.classList.contains('hidden'), '外側クリックで閉じません。');
            trigger.click();
            await respond(await next(), payload(5));
            countIs(5);
            assert(trigger.getAttribute('aria-expanded') === 'true' && !panel.classList.contains('hidden'), '再開閉で開きません。');
        });

        assert(!requests.some((request) => !request.responded), '未完了の通知 API があります。');
        return results;
    } finally {
        // 失敗途中の Promise も完了させ、実 API へ更新処理が漏れないようにする。
        for (const request of requests) {
            if (!request.responded) {
                request.responded = true;
                request.resolve(new Response('{"message":"browser test cleanup"}', {
                    status: 503, headers: { 'Content-Type': 'application/json' },
                }));
            }
        }
        await flush();
        if (trigger.getAttribute('aria-expanded') === 'true') trigger.click();
        window.fetch = originalFetch;
    }
}
