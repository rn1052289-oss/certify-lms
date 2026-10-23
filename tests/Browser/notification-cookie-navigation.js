/**
 * 専用 DB の通知「単一既読の回帰確認」を、通常ログイン済みの実画面から開く回帰確認。
 * 実 fetch の応答を一時保留し、遷移前の件数更新まで検証する。API 応答は偽装しない。
 * ページ読込後に実行し、遷移先で sessionStorage の notification-cookie-navigation を確認する。
 * 成功時は status=PASS、expectedPath と現在の pathname、遷移先の未読数も照合する。
 */
async () => {
    const resultKey = 'notification-cookie-navigation';
    const assert = (condition, message) => {
        if (!condition) throw new Error(message);
    };
    const waitFor = async (condition, message) => {
        const deadline = Date.now() + 10000;
        while (!condition()) {
            assert(Date.now() < deadline, message);
            await new Promise((resolve) => setTimeout(resolve, 10));
        }
    };
    const save = (result) => sessionStorage.setItem(resultKey, JSON.stringify(result));
    const root = document.querySelector('[data-notification-popover-root]');
    assert(root, '通常ログイン済みの通知ベルがある画面が必要です。');
    const find = (name) => root.querySelector(`[data-notification-popover-${name}]`);
    const endpoint = '/api/v1/notifications';
    const originalFetch = window.fetch;
    const originalUrl = window.location.href;
    const headers = { Accept: 'application/json' };
    let releaseResponse;
    let responseReady = false;
    let released = false;
    let finished = false;
    let navigationResult;

    save({ status: 'RUNNING' });
    try {
        const initial = await originalFetch(endpoint, { headers, credentials: 'same-origin' });
        assert(initial.status === 200, 'ログイン Cookie で通知 API を取得できません。');
        const payload = await initial.json();
        const notification = payload.notifications.find((record) => record.title === '単一既読の回帰確認' && record.unread);
        assert(notification, '専用 DB に未読の検証通知を準備してください。');
        const expectedCount = payload.unread_count - 1;
        const expectedPath = `/notifications/${notification.id}`;

        const withoutCookie = await originalFetch(endpoint, { headers, credentials: 'omit' });
        assert(withoutCookie.status === 401, 'Cookie なしの API が認証を通過しました。');
        const csrfCookie = await originalFetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
        assert(csrfCookie.status === 204, 'CSRF Cookie を取得できません。');
        const missingCsrf = await originalFetch(`${endpoint}/${notification.id}/read`, {
            method: 'POST', headers, credentials: 'same-origin',
        });
        assert(missingCsrf.status === 419, 'CSRF なしの単一既読が拒否されません。');

        if (find('trigger').getAttribute('aria-expanded') !== 'true') find('trigger').click();
        const rowSelector = `[data-notification-popover-row][href$="/${notification.id}/read"]`;
        await waitFor(() => root.querySelector(rowSelector), '検証通知が表示されません。');
        assert(find('trigger').getAttribute('aria-label') === `通知 (${payload.unread_count} 件未読)`, '操作前の未読数が不正です。');

        const navigation = new Promise((resolve) => {
            window.addEventListener('pagehide', () => {
                try {
                    assert(released, '単一既読の成功応答より前に遷移しました。');
                    assert(find('badge').textContent === (expectedCount > 99 ? '99+' : String(expectedCount)), '遷移直前のバッジが更新されません。');
                    assert(find('badge').classList.contains('hidden') === (expectedCount === 0), '遷移直前のバッジ表示が不正です。');
                    assert(find('unread-count').textContent === String(expectedCount), '遷移直前の未読タブ件数が更新されません。');
                    assert(find('trigger').getAttribute('aria-label') === `通知 (${expectedCount} 件未読)`, '遷移直前の ARIA 件数が更新されません。');
                    navigationResult = { status: 'PASS', expectedPath, expectedCount, beforeCount: payload.unread_count };
                } catch (error) {
                    navigationResult = { status: 'FAIL', message: error.message };
                }
                save(navigationResult);
                finished = true;
                resolve(navigationResult);
            }, { once: true });
        });

        window.fetch = async (input, options = {}) => {
            const url = new URL(typeof input === 'string' ? input : input.url, window.location.href);
            if (url.pathname !== `${endpoint}/${notification.id}/read` || options.method !== 'POST') {
                return originalFetch.call(window, input, options);
            }
            assert(options.credentials === 'same-origin', '単一既読が Cookie を送信しません。');
            assert(new Headers(options.headers).get('X-CSRF-TOKEN') === document.querySelector('meta[name="csrf-token"]').content, 'ページの CSRF ヘッダが送信されません。');
            const response = await originalFetch.call(window, input, options);
            assert(response.status === 200, '実 Cookie / CSRF で単一既読が成功しません。');
            const result = await response.clone().json();
            assert(result.unread_count === expectedCount, '実 API の単一既読後の件数が不正です。');
            assert(new URL(result.url).pathname === expectedPath, '実 API の遷移先が不正です。');
            return new Promise((resolve) => {
                releaseResponse = () => resolve(response);
                responseReady = true;
            });
        };

        root.querySelector(rowSelector).click();
        await waitFor(() => responseReady || finished, '単一既読の実応答を受信できません。');
        assert(!finished && window.location.href === originalUrl, '応答保留中に画面が遷移しました。');
        assert(find('trigger').getAttribute('aria-label') === `通知 (${payload.unread_count} 件未読)`, '応答保留中に件数が更新されました。');
        assert(find('mark-all').disabled, '単一既読の応答保留中に操作ロックが解除されました。');
        released = true;
        releaseResponse();
        await waitFor(() => finished, '単一既読の成功後に遷移しません。');
        return await navigation;
    } catch (error) {
        save({ status: 'FAIL', message: error.message });
        throw error;
    } finally {
        window.fetch = originalFetch;
        releaseResponse?.();
    }
}
