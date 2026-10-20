// 実行: node --experimental-vm-modules --test tests/JavaScript/AiChatWidgetContextTest.mjs
// DOMと通信境界を置き換え、依存パッケージを読まずに実際のwidget制御を検証する。
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';

const source = await fs.readFile(new URL('../../resources/js/ai-chat/floating-widget.js', import.meta.url), 'utf8');
const conversationKey = 'certify.aichat.current_conversation_id';
const sectionKey = 'certify.aichat.current_conversation_section_id';

function deferred() {
    let resolve;
    const promise = new Promise((done) => { resolve = done; });
    return { promise, resolve };
}

function node() {
    return {
        handlers: new Map(), dataset: {}, style: {}, disabled: false, value: '', output: [],
        classList: { add() {}, remove() {}, contains() { return true; } },
        addEventListener(name, callback) { this.handlers.set(name, callback); },
        querySelector() { return null; }, querySelectorAll() { return []; },
        setAttribute() {}, removeAttribute() {}, focus() {}, remove() {},
        appendChild(value) { this.output.push(value); },
        insertAdjacentHTML(_position, value) { this.output.push(value); },
        set innerHTML(value) { this.output = value ? [value] : []; },
        get innerHTML() { return this.output.join(''); },
    };
}

async function widget(response, createdId = 'new-conversation', creationResponse = null) {
    const elements = Object.fromEntries(['fab', 'panel', 'close', 'fullscreen', 'widget-input', 'widget-send', 'messages', 'new']
        .map((key) => [`[data-ai-chat-${key}]`, node()]));
    const root = node();
    root.dataset = { storeUrl: '/ai-chat/conversations', fullscreenBaseUrl: '/ai-chat/conversations' };
    root.querySelector = (key) => elements[key] ?? null;
    const stored = new Map([[conversationKey, 'old-conversation'], [sectionKey, '']]);
    const requests = [];
    const sends = [];
    const context = vm.createContext({
        URLSearchParams,
        document: { querySelector: (key) => key === '[data-ai-chat-widget]' ? root : null, createElement: node, addEventListener() {} },
        sessionStorage: { getItem: (key) => stored.get(key) ?? null, setItem: (key, value) => stored.set(key, value), removeItem: (key) => stored.delete(key) },
        window: { location: { href: '' } },
        fetch: async (url, options) => {
            requests.push({ url, options });
            if (options?.method === 'POST') return creationResponse ?? { ok: true, status: 201, json: async () => ({ conversation: { id: createdId }, assistant_message: { content: '新しい回答', status: 'completed' } }) };
            return response;
        },
    });
    const module = new vm.SourceTextModule(source, { context });
    await module.link(async (specifier) => {
        const exports = specifier.endsWith('chat-client.js') ? { AiChatClient: class {
            constructor(options) { this.options = options; }
            async sendSync(content) { sends.push({ url: this.options.storeUrl, content }); }
        } } : specifier.endsWith('message-renderer.js') ? { escapeHtml: (text) => text } : { renderMarkdown: (text) => text };
        return new vm.SyntheticModule(Object.keys(exports), function () {
            for (const [key, value] of Object.entries(exports)) this.setExport(key, value);
        }, { context });
    });
    await module.evaluate();
    module.namespace.initAiChatWidget();
    return { elements, stored, requests, sends, context };
}

function history(enrollmentId = 'first-enrollment') {
    return {
        conversation: { id: 'old-conversation', enrollment_id: 'first-enrollment', section_id: null },
        widget_context: { enrollment_id: enrollmentId, section_id: null },
        messages: [{ role: 'assistant', content: '以前の資格の履歴' }],
    };
}

async function settle() {
    for (let i = 0; i < 12; i++) await Promise.resolve();
}

test('資格切替後は履歴確認が完了するまで送信せず、新しい会話へ送る', async () => {
    const pending = deferred();
    const app = await widget({ ok: true, json: () => pending.promise });
    app.elements['[data-ai-chat-widget-input]'].value = '切替後の質問';
    const sending = app.elements['[data-ai-chat-widget-send]'].handlers.get('click')();
    await settle();
    assert.equal(app.sends.length, 0);
    assert.equal(app.requests.length, 1);
    pending.resolve(history('second-enrollment'));
    await sending;
    await settle();
    assert.equal(app.requests[0].url, '/ai-chat/conversations/old-conversation?source=widget');
    assert.equal(app.sends.length, 0);
    assert.equal(app.requests.filter((request) => request.options?.method === 'POST').length, 1);
    assert.equal(JSON.parse(app.requests.at(-1).options.body).message, '切替後の質問');
    assert.equal(app.stored.get(conversationKey), 'new-conversation');
    assert.ok(!app.elements['[data-ai-chat-messages]'].innerHTML.includes('以前の資格の履歴'));
});

test('同じ資格は履歴を復元して同じ会話へ送る', async () => {
    const app = await widget({ ok: true, json: async () => history() });
    await settle();
    app.elements['[data-ai-chat-widget-input]'].value = '続きの質問';
    await app.elements['[data-ai-chat-widget-send]'].handlers.get('click')();
    await settle();
    assert.equal(app.requests.length, 1);
    assert.equal(app.sends[0].url, '/ai-chat/conversations/old-conversation/messages');
    assert.ok(app.elements['[data-ai-chat-messages]'].innerHTML.includes('以前の資格の履歴'));
});

test('履歴取得中に新規を押しても遅い履歴が新しい画面へ戻らない', async () => {
    const pending = deferred();
    const app = await widget({ ok: true, json: () => pending.promise });
    app.elements['[data-ai-chat-new]'].handlers.get('click')();
    pending.resolve(history());
    await settle();
    assert.equal(app.stored.has(conversationKey), false);
    assert.ok(!app.elements['[data-ai-chat-messages]'].innerHTML.includes('以前の資格の履歴'));
});

test('全画面遷移も資格確認を待ち、不一致なら古い会話を開かない', async () => {
    const pending = deferred();
    const app = await widget({ ok: true, json: () => pending.promise });
    const opening = app.elements['[data-ai-chat-fullscreen]'].handlers.get('click')();
    await settle();
    assert.equal(app.context.window.location.href, '');
    pending.resolve(history('second-enrollment'));
    await opening;
    assert.equal(app.context.window.location.href, '/ai-chat');
});

test('削除済み会話を復元できない場合は新規へ切り替える', async () => {
    const app = await widget({ ok: false });
    await settle();
    assert.equal(app.stored.has(conversationKey), false);
    app.elements['[data-ai-chat-widget-input]'].value = '新しい質問';
    await app.elements['[data-ai-chat-widget-send]'].handlers.get('click')();
    await settle();
    assert.equal(app.sends.length, 0);
    assert.equal(app.requests.filter((request) => request.options?.method === 'POST').length, 1);
    assert.equal(JSON.parse(app.requests.at(-1).options.body).message, '新しい質問');
});


test('新規操作後に同じ会話IDが再利用されても、古い履歴応答を混ぜない', async () => {
    const pending = deferred();
    const app = await widget({ ok: true, json: () => pending.promise }, 'old-conversation');
    app.elements['[data-ai-chat-new]'].handlers.get('click')();
    app.elements['[data-ai-chat-widget-input]'].value = '新しく入力した質問';
    app.elements['[data-ai-chat-widget-send]'].handlers.get('click')();
    await settle();
    assert.equal(app.stored.get(conversationKey), 'old-conversation');
    pending.resolve(history());
    await settle();
    assert.equal(app.stored.get(conversationKey), 'old-conversation');
    assert.ok(!app.elements['[data-ai-chat-messages]'].innerHTML.includes('以前の資格の履歴'));
});


for (const status of [429, 503]) {
    test(`初回${status}拒否では会話IDを保存せず、質問を入力欄へ戻す`, async () => {
        const app = await widget({ ok: false }, 'unused', { ok: false, status, json: async () => ({ message: '受付できません' }) });
        await settle();
        const input = app.elements['[data-ai-chat-widget-input]'];
        input.value = '失わない質問';
        app.elements['[data-ai-chat-widget-send]'].handlers.get('click')();
        await settle();
        assert.equal(app.stored.has(conversationKey), false);
        assert.equal(input.value, '失わない質問');
        assert.equal(input.disabled, false);
        assert.equal(app.sends.length, 0);
        assert.equal(app.requests.filter((request) => request.options?.method === 'POST').length, 1);
    });
}

test('初回LLM失敗は会話IDを保存し、次の質問で同じ会話を再開する', async () => {
    const app = await widget({ ok: false }, 'saved-error-conversation', {
        ok: false, status: 502,
        json: async () => ({ conversation: { id: 'saved-error-conversation' }, assistant_message: { content: 'AI が応答できませんでした', status: 'error' }, upstream_status: 503 }),
    });
    await settle();
    const input = app.elements['[data-ai-chat-widget-input]'];
    input.value = '最初の質問';
    app.elements['[data-ai-chat-widget-send]'].handlers.get('click')();
    await settle();
    assert.equal(app.stored.get(conversationKey), 'saved-error-conversation');
    assert.equal(app.sends.length, 0);
    assert.ok(app.elements['[data-ai-chat-messages]'].innerHTML.includes('AI が応答できませんでした'));
    input.value = 'やり直す質問';
    app.elements['[data-ai-chat-widget-send]'].handlers.get('click')();
    await settle();
    assert.equal(app.requests.filter((request) => request.options?.method === 'POST').length, 1);
    assert.equal(app.sends.length, 1);
    assert.equal(app.sends[0].url, '/ai-chat/conversations/saved-error-conversation/messages');
    assert.equal(app.sends[0].content, 'やり直す質問');
});
