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
        querySelector() { return null; }, appendChild(value) { this.output.push(value); }, remove() { this.removed = true; },
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
    const template = { content: { firstElementChild: { cloneNode: node } } };
    const elements = {
        '[data-ai-chat-form]': form, '[data-message-list]': list, '[data-message-scroller]': scroller,
        '[data-ai-chat-message-template]': template,
    };
    const requests = [];
    const context = vm.createContext({
        document: { querySelector: (selector) => elements[selector] ?? null, getElementById() { return null; } },
        fetch: async (_url, options) => {
            requests.push(JSON.parse(options.body).content);
            return response();
        },
    });
    const module = new vm.SourceTextModule(source, { context });
    await module.link(async (specifier) => {
        if (specifier.endsWith('chat-client.js')) return new vm.SourceTextModule(clientSource, { context });
        return new vm.SyntheticModule(['renderFullScreenMessage'], function () {
            this.setExport('renderFullScreenMessage', (_list, message) => list.output.push(message));
        }, { context });
    });
    form.requestSubmit = () => form.handlers.get('submit')({ preventDefault() {} });
    await module.evaluate();
    module.namespace.initAiChatFullScreen();
    return { input, submit, list, requests, send: form.requestSubmit };
}

for (const status of [409, 422, 429, 503, 502]) {
    test(`全画面${status}では未受付の質問だけ入力欄へ戻す`, async () => {
        const app = await screen(() => ({ ok: false, status, json: async () => ({ upstream_status: 503 }) }));
        app.input.value = '失わず再送する質問';
        await app.send();
        assert.equal(app.input.value, status === 502 ? '' : '失わず再送する質問');
        assert.equal(app.submit.disabled, false);
        assert.ok(app.list.output.some((message) => message.content === '失わず再送する質問'));
        if (status === 409) {
            assert.ok(app.list.output.some((message) => message.content === 'AI が応答中です。完了後に送信してください。'));
        }
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

for (const trigger of ['submit', 'metaKey', 'ctrlKey']) {
    test(`応答待ち中の${trigger}再送を防ぎ、下書きと考え中表示を保持する`, async () => {
        let resolve;
        const pending = new Promise((done) => { resolve = done; });
        let first = true;
        const ok = { ok: true, status: 200, json: async () => ({ assistant_message: { content: '回答' } }) };
        const app = await screen(() => {
            if (first) { first = false; return pending; }
            return ok;
        });
        app.input.value = '最初の質問';
        const sending = app.send();
        app.input.value = '次の質問の下書き';
        if (trigger === 'submit') {
            await app.send();
        } else {
            await app.input.handlers.get('keydown')({ key: 'Enter', [trigger]: true, preventDefault() {} });
        }

        assert.deepEqual(app.requests, ['最初の質問']);
        assert.equal(app.input.value, '次の質問の下書き');
        assert.equal(app.submit.disabled, true);
        assert.equal(app.list.output.filter((item) => item.dataset?.typing === '1' && !item.removed).length, 1);

        resolve(ok);
        await sending;
        assert.equal(app.submit.disabled, false);
        assert.equal(app.input.value, '次の質問の下書き');
        assert.equal(app.list.output.filter((item) => item.dataset?.typing === '1' && !item.removed).length, 0);
        await app.send();
        assert.deepEqual(app.requests, ['最初の質問', '次の質問の下書き']);
    });
}

test('通信例外の後も送信ロックを解除して次の質問を送れる', async () => {
    let first = true;
    const app = await screen(() => {
        if (first) { first = false; throw new Error('Network unavailable'); }
        return { ok: true, status: 200, json: async () => ({ assistant_message: { content: '回答' } }) };
    });
    app.input.value = '最初の質問';
    await app.send();
    assert.equal(app.submit.disabled, false);
    app.input.value = '再送する質問';
    await app.send();
    assert.deepEqual(app.requests, ['最初の質問', '再送する質問']);
});
