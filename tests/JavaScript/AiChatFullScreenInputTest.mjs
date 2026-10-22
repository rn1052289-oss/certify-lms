// 実行: node --experimental-vm-modules --test tests/JavaScript/AiChatFullScreenInputTest.mjs
// 実際の画面制御とHTTPクライアントを、依存パッケージなしで検証する。
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';

const source = await fs.readFile(new URL('../../resources/js/ai-chat/full-screen.js', import.meta.url), 'utf8');
const clientSource = await fs.readFile(new URL('../../resources/js/ai-chat/chat-client.js', import.meta.url), 'utf8');

function node() {
    return {
        value: '', style: {}, dataset: {}, handlers: new Map(), output: [],
        addEventListener(name, callback) { this.handlers.set(name, callback); },
        querySelector() { return null; }, appendChild(value) { this.output.push(value); }, remove() {},
    };
}

async function screen(response) {
    const input = node();
    const submit = node();
    const form = node();
    form.dataset.storeUrl = '/ai-chat/conversations/saved/messages';
    form.querySelector = (selector) => selector === '[data-ai-chat-textarea]' ? input : submit;
    const list = node();
    const scroller = node();
    const elements = { '[data-ai-chat-form]': form, '[data-message-list]': list, '[data-message-scroller]': scroller };
    const context = vm.createContext({
        document: { querySelector: (selector) => elements[selector] ?? null, getElementById() { return null; } },
        fetch: async () => response(),
    });
    const module = new vm.SourceTextModule(source, { context });
    await module.link(async (specifier) => {
        if (specifier.endsWith('chat-client.js')) return new vm.SourceTextModule(clientSource, { context });
        return new vm.SyntheticModule(['renderFullScreenMessage'], function () {
            this.setExport('renderFullScreenMessage', (_list, message) => list.output.push(message));
        }, { context });
    });
    await module.evaluate();
    module.namespace.initAiChatFullScreen();
    return { input, submit, list, send: () => form.handlers.get('submit')({ preventDefault() {} }) };
}

for (const status of [422, 429, 503, 502]) {
    test(`全画面${status}では未受付の質問だけ入力欄へ戻す`, async () => {
        const app = await screen(() => ({ ok: false, status, json: async () => ({ upstream_status: 503 }) }));
        app.input.value = '失わず再送する質問';
        await app.send();
        assert.equal(app.input.value, status === 502 ? '' : '失わず再送する質問');
        assert.equal(app.submit.disabled, false);
        assert.ok(app.list.output.some((message) => message.content === '失わず再送する質問'));
    });
}

test('全画面の受付拒否で送信中に書いた新しい下書きを上書きしない', async () => {
    let resolve;
    const pending = new Promise((done) => { resolve = done; });
    const app = await screen(() => pending);
    app.input.value = '送った質問';
    const sending = app.send();
    app.input.value = '新しい下書き';
    resolve({ ok: false, status: 429 });
    await sending;
    assert.equal(app.input.value, '新しい下書き');
});
