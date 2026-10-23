/**
 * 通知ベルの開閉・タブ別取得・既読化。
 * 提供 Blade の data-* と行 template を使い、成功応答後にだけ件数を更新する。
 */
import { getJson, postJson } from '../utils/fetch-json';

export function initNotificationPopovers() {
    document.querySelectorAll('[data-notification-popover-root]').forEach((root) => {
        const trigger = root.querySelector('[data-notification-popover-trigger]');
        const panel = root.querySelector('[data-notification-popover-panel]');
        const items = root.querySelector('[data-notification-popover-items]');
        const template = root.querySelector('[data-notification-popover-row-template]');
        const loading = root.querySelector('[data-notification-popover-loading]');
        const empty = root.querySelector('[data-notification-popover-empty]');
        const markAll = root.querySelector('[data-notification-popover-mark-all]');
        const tabs = root.querySelectorAll('[data-notification-popover-tab]');
        if (!trigger || !panel || !items || !template || !loading || !empty || !markAll) return;

        let tab = 'all';
        let open = false;
        let pending = false;
        let sequence = 0;
        const endpoint = '/api/v1/notifications';

        function updateCount(count) {
            const badge = root.querySelector('[data-notification-popover-badge]');
            const unreadCount = root.querySelector('[data-notification-popover-unread-count]');
            if (badge) {
                badge.textContent = count > 99 ? '99+' : String(count);
                badge.classList.toggle('hidden', count === 0);
            }
            if (unreadCount) unreadCount.textContent = String(count);
            trigger.setAttribute('aria-label', `通知 (${count} 件未読)`);
        }

        function showError() {
            empty.textContent = '通知を取得・更新できませんでした。もう一度お試しください。';
            empty.setAttribute('role', 'alert');
            empty.classList.remove('hidden');
        }

        function setPending(value) {
            pending = value;
            markAll.disabled = value;
            tabs.forEach((button) => { button.disabled = value; });
        }

        function render(notifications) {
            items.replaceChildren();
            notifications.forEach((notification) => {
                const fragment = template.content.cloneNode(true);
                const row = fragment.querySelector('[data-notification-popover-row]');
                row.href = `${endpoint}/${encodeURIComponent(notification.id)}/read`;
                row.dataset.unread = String(notification.unread);
                fragment.querySelector('li').classList.toggle('bg-primary-50/30', notification.unread);
                fragment.querySelector('[data-notification-popover-row-dot]').classList.toggle('invisible', !notification.unread);
                fragment.querySelector('[data-notification-popover-row-title]').textContent = notification.title;
                fragment.querySelector('[data-notification-popover-row-message]').textContent = notification.message;
                fragment.querySelector('[data-notification-popover-row-time]').textContent = notification.created_relative;
                row.addEventListener('click', async (event) => {
                    event.preventDefault();
                    if (pending) return;
                    setPending(true);
                    ++sequence;
                    try {
                        const result = await postJson(row.href);
                        updateCount(result.unread_count);
                        const target = new URL(result.url, window.location.origin);
                        if (target.origin !== window.location.origin) throw new Error('Invalid notification URL');
                        window.location.assign(target.href);
                    } catch {
                        showError();
                    } finally {
                        setPending(false);
                    }
                });
                items.append(fragment);
            });
            empty.textContent = '通知はありません。';
            empty.removeAttribute('role');
            empty.classList.toggle('hidden', notifications.length !== 0);
        }

        async function load() {
            const current = ++sequence;
            items.replaceChildren();
            empty.classList.add('hidden');
            loading.classList.remove('hidden');
            try {
                const result = await getJson(`${endpoint}?tab=${tab}`, { cache: 'no-store' });
                if (current !== sequence) return;
                render(result.notifications);
                updateCount(result.unread_count);
            } catch {
                if (current === sequence) showError();
            } finally {
                if (current === sequence) loading.classList.add('hidden');
            }
        }

        function close() {
            open = false;
            panel.classList.add('hidden', 'opacity-0', '-translate-y-1');
            panel.classList.remove('flex');
            panel.style.display = 'none';
            trigger.setAttribute('aria-expanded', 'false');
        }

        trigger.addEventListener('click', () => {
            if (open) {
                close();
                return;
            }
            open = true;
            panel.style.display = '';
            panel.classList.remove('hidden', 'opacity-0', '-translate-y-1');
            panel.classList.add('flex');
            trigger.setAttribute('aria-expanded', 'true');
            if (!pending) load();
        });

        tabs.forEach((button) => {
            button.addEventListener('click', () => {
                if (pending) return;
                tab = button.dataset.notificationPopoverTab;
                tabs.forEach((item) => item.setAttribute('aria-selected', String(item === button)));
                load();
            });
        });

        markAll.addEventListener('click', async () => {
            if (pending) return;
            setPending(true);
            ++sequence;
            try {
                const result = await postJson(`${endpoint}/read-all`);
                updateCount(result.unread_count);
                await load();
            } catch {
                showError();
            } finally {
                loading.classList.add('hidden');
                setPending(false);
            }
        });

        document.addEventListener('click', (event) => {
            if (open && !root.contains(event.target)) close();
        });
        root.addEventListener('keydown', (event) => {
            if (open && event.key === 'Escape') {
                close();
                trigger.focus();
            }
        });
    });
}
