const assert = require('node:assert/strict');
const {randomFillSync} = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const uuidSource = fs.readFileSync(path.join(root, 'public/js/uuid.js'), 'utf8');
const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

async function main() {
    const window = {crypto: {getRandomValues: bytes => randomFillSync(bytes)}};
    const context = vm.createContext({window});
    vm.runInContext(uuidSource, context);
    const ids = new Set(Array.from({length: 1000}, () => window.MestoUUID()));
    assert.equal(ids.size, 1000);
    for (const id of ids) assert.match(id, uuidPattern);
    console.log('PASS: HTTP without randomUUID produces distinct RFC UUID v4 identifiers');

    const nativeId = '00000000-0000-4000-8000-000000000000';
    window.crypto.randomUUID = () => nativeId;
    assert.equal(window.MestoUUID(), nativeId);
    delete window.crypto.randomUUID;

    const message = {textContent: '', dataset: {}};
    const button = {disabled: false};
    let submit;
    const form = {
        dataset: {version: '0'},
        elements: {
            salary_negotiable: {checked: true, addEventListener() {}},
            salary_from: {}, salary_to: {},
        },
        querySelector: () => button,
        addEventListener: (event, handler) => { if (event === 'submit') submit = handler; },
    };
    const payloads = [];
    const selectors = {
        '#vacancyForm': form,
        '#vacancyStatus': message,
        'meta[name="registration-csrf"]': {content: 'test-csrf'},
    };
    Object.assign(context, {
        document: {querySelectorAll: () => [], querySelector: selector => selectors[selector]},
        FormData: class { *[Symbol.iterator]() { yield ['title', 'PHP developer']; } },
        history: {replaceState() {}},
        location: {assign() { throw new Error('Unexpected redirect'); }},
        fetch: async (url, options) => {
            assert.equal(url, '/cabinet/vacancies');
            const body = JSON.parse(options.body);
            assert.match(body.id, uuidPattern);
            payloads.push(body);
            if (payloads.length === 1) throw new Error('Temporary connection failure');
            return {status: 200, ok: true, json: async () => ({success: true,
                data: {id: body.id, version: payloads.length, content: body.content}})};
        },
    });
    vm.runInContext(fs.readFileSync(path.join(root, 'public/js/employer-cabinet.js'), 'utf8'), context);
    for (let attempt = 0; attempt < 3; attempt++) await submit({preventDefault() {}});
    assert.equal(payloads.length, 3);
    assert.equal(new Set(payloads.map(body => body.id)).size, 1);
    assert.equal(button.disabled, false);
    assert.equal(message.dataset.error, 'false');
    assert.match(message.textContent, /Черновик сохранён/);
    console.log('PASS: draft save reaches API on HTTP and keeps its identifier after retry');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
