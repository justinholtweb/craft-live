import test from 'node:test';
import assert from 'node:assert/strict';
import { loadComposer } from './helpers.mjs';

/**
 * The composer's local queue and its form encoding.
 *
 * The queue is what stands between a journalist on bad wifi and a lost paragraph, and the encoding
 * is what stands between a custom field and silence — a JSON body leaves `liveFields[fields][x]` as
 * one flat key, and PHP drops the value without a word. Both have already broken once.
 */

test('the queue round-trips items', () => {
    const { Queue } = loadComposer();
    const queue = new Queue('1-2-3');

    queue.add({ clientId: 'a', body: 'first' });
    queue.add({ clientId: 'b', body: 'second' });

    assert.deepEqual(queue.all().map((i) => i.clientId), ['a', 'b']);
});

test('removing one item leaves the rest', () => {
    const { Queue } = loadComposer();
    const queue = new Queue('1-2-3');

    queue.add({ clientId: 'a', body: 'first' });
    queue.add({ clientId: 'b', body: 'second' });
    queue.remove('a');

    assert.deepEqual(queue.all().map((i) => i.clientId), ['b']);
});

test('removing something that is not there is harmless', () => {
    const { Queue } = loadComposer();
    const queue = new Queue('1-2-3');

    queue.add({ clientId: 'a', body: 'first' });
    queue.remove('nope');

    assert.equal(queue.all().length, 1);
});

test('queues are keyed per post, so two open matches do not share a backlog', () => {
    const { Queue, store, STORAGE_PREFIX } = loadComposer();

    new Queue('1-2-3').add({ clientId: 'a' });
    new Queue('9-9-9').add({ clientId: 'b' });

    assert.deepEqual(JSON.parse(store[`${STORAGE_PREFIX}1-2-3`]).map((i) => i.clientId), ['a']);
    assert.deepEqual(JSON.parse(store[`${STORAGE_PREFIX}9-9-9`]).map((i) => i.clientId), ['b']);
});

test('a corrupt queue reads as empty rather than throwing', () => {
    const { Queue, store, STORAGE_PREFIX } = loadComposer();
    store[`${STORAGE_PREFIX}1-2-3`] = 'not json{';

    assert.deepEqual(new Queue('1-2-3').all(), []);
});

test('an empty queue starts empty', () => {
    const { Queue } = loadComposer();

    assert.deepEqual(new Queue('fresh').all(), []);
});

test('form encoding keeps bracket notation intact', () => {
    const { toFormData } = loadComposer();

    const body = toFormData({
        postId: 923,
        'liveFields[fields][scorer]': 'A. Smith',
    });

    // PHP expands this into fields[scorer] only because it arrives form-encoded. As JSON it stays
    // one meaningless key and the value is silently lost.
    assert.equal(body.get('liveFields[fields][scorer]'), 'A. Smith');
    assert.equal(body.get('postId'), '923');
});

test('array values are appended once each', () => {
    const { toFormData } = loadComposer();

    const body = toFormData({ 'liveFields[fields][tags][]': ['a', 'b', 'c'] });

    assert.deepEqual(body.getAll('liveFields[fields][tags][]'), ['a', 'b', 'c']);
});

test('null and undefined are left out entirely', () => {
    const { toFormData } = loadComposer();

    const body = toFormData({ title: null, body: undefined, kept: '' });

    assert.equal(body.has('title'), false);
    assert.equal(body.has('body'), false);
    // An empty string is a real value — it is how a field gets cleared.
    assert.equal(body.has('kept'), true);
});

test('a zero survives encoding', () => {
    const { toFormData } = loadComposer();

    const body = toFormData({ highlight: 0, pinned: false });

    assert.equal(body.get('highlight'), '0');
    assert.equal(body.get('pinned'), 'false');
});
