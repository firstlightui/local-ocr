import assert from 'node:assert/strict';
import { beforeEach, test } from 'node:test';

// A minimal web view: the bridge endpoint and the document that native events arrive on.
const document = new EventTarget();
document.querySelector = () => null;
globalThis.document = document;

let calls;
let respond;

globalThis.fetch = async (url, { body }) => {
    const call = JSON.parse(body);
    calls.push(call);

    return { json: async () => respond(call) };
};

const native = (event, payload) => document.dispatchEvent(new CustomEvent('native-event', { detail: { event, payload } }));

const { capturePages, recognizeText, Discard, Events, LocalOcrError } = await import('../../resources/js/index.js');

beforeEach(() => {
    calls = [];
    respond = ({ params }) => ({ status: 'success', data: { status: 'pending', id: params.id } });
});

test('recognizeText settles only on the event carrying its own id', async () => {
    const result = recognizeText([{ path: '/a.jpg', width: 1, height: 1 }, '/b.jpg'], { languages: ['en-AU'] });
    await new Promise(setImmediate);

    const [{ method, params }] = calls;
    assert.equal(method, 'LocalOcr.Recognize');
    assert.deepEqual(params.paths, ['/a.jpg', '/b.jpg']);
    assert.deepEqual(params.languages, ['en-AU']);

    native(Events.TextRecognized, { id: 'someone-else', result: { text: 'wrong' } });
    native(Events.TextRecognized, { id: params.id, result: { text: 'right' } });

    assert.deepEqual(await result, { text: 'right' });
});

test('recognizeText rejects with the failure code', async () => {
    const result = recognizeText('/a.jpg');
    await new Promise(setImmediate);

    native(Events.TextRecognitionFailed, { id: calls[0].params.id, code: 'DECODE_FAILED', message: 'Could not decode page 1' });

    await assert.rejects(result, (error) => error instanceof LocalOcrError && error.code === 'DECODE_FAILED');
});

test('a rejected bridge call rejects once, even though native also sends the failure event', async () => {
    respond = () => ({ status: 'error', code: 'FILE_NOT_FOUND', message: 'No image file at /missing.jpg' });

    await assert.rejects(recognizeText('/missing.jpg'), { code: 'FILE_NOT_FOUND' });
});

test('capturePages resolves with the pages, or null when cancelled', async () => {
    const captured = capturePages();
    await new Promise(setImmediate);
    native(Events.PagesCaptured, { id: calls[0].params.id, pages: [{ path: '/p.jpg', width: 2, height: 3 }] });
    assert.deepEqual(await captured, [{ path: '/p.jpg', width: 2, height: 3 }]);

    const cancelled = capturePages();
    await new Promise(setImmediate);
    native(Events.CaptureCancelled, { id: calls[1].params.id });
    assert.equal(await cancelled, null);
});

test('Discard sends paths from pages or strings', async () => {
    respond = () => ({ status: 'success', data: { deleted: 2 } });

    assert.deepEqual(await Discard([{ path: '/a.jpg' }, '/b.jpg']), { deleted: 2 });
    assert.deepEqual(calls[0], { method: 'LocalOcr.Discard', params: { paths: ['/a.jpg', '/b.jpg'] } });
});
