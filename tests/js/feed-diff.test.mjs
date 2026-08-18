import test from 'node:test';
import assert from 'node:assert/strict';
import { loadFeed, head, holding } from './helpers.mjs';

const { computeWanted, MAX_CATCHUP } = loadFeed();

/**
 * The client's whole correctness lives in this function: what has this reader not got yet?
 *
 * It has to answer three different questions at once — new updates, edits to updates already on
 * screen, and deletions — from a file that only ever says what currently exists.
 */

test('a reader holding everything wants nothing', () => {
    const result = computeWanted(
        head({ seq: 3, updates: [{ seq: 3, rev: 0 }, { seq: 2, rev: 0 }, { seq: 1, rev: 0 }] }),
        holding([[1, 0], [2, 0], [3, 0]]),
    );

    assert.deepEqual(result.wanted, []);
    assert.deepEqual(result.removed, []);
    assert.equal(result.farBehind, false);
});

test('a new update is wanted', () => {
    const result = computeWanted(
        head({ seq: 4, updates: [{ seq: 4, rev: 0 }, { seq: 3, rev: 0 }] }),
        holding([[3, 0]]),
    );

    assert.deepEqual(result.wanted, [{ seq: 4, rev: 0 }]);
});

test('an edit to an update already on screen is wanted again', () => {
    // This is what `rev` is for. Without it a correction never reaches anyone who was already
    // reading — the worst kind of failure for a live blog, because it is invisible.
    const result = computeWanted(
        head({ seq: 3, updates: [{ seq: 3, rev: 0 }, { seq: 2, rev: 1 }] }),
        holding([[2, 0], [3, 0]]),
    );

    assert.deepEqual(result.wanted, [{ seq: 2, rev: 1 }]);
});

test('revs are compared as strings, so 0 and "0" agree', () => {
    const result = computeWanted(
        head({ seq: 1, updates: [{ seq: 1, rev: 0 }] }),
        { 1: 0 },
    );

    assert.deepEqual(result.wanted, []);
});

test('updates are fetched oldest first, so the feed assembles in order', () => {
    const result = computeWanted(
        head({ seq: 9, updates: [{ seq: 9, rev: 0 }, { seq: 8, rev: 0 }, { seq: 7, rev: 0 }] }),
        {},
    );

    assert.deepEqual(result.wanted.map((u) => u.seq), [7, 8, 9]);
});

test('a deletion is reported only for something the reader is actually showing', () => {
    const result = computeWanted(
        head({ seq: 5, updates: [{ seq: 5, rev: 0 }], removed: [2, 3] }),
        holding([[2, 0], [5, 0]]),
    );

    // 3 was deleted before this reader ever saw it; taking it down is not a thing that needs doing.
    assert.deepEqual(result.removed, [2]);
});

test('a reader far behind is told to catch up in one request', () => {
    const updates = [];
    for (let seq = 1; seq <= MAX_CATCHUP + 5; seq++) {
        updates.push({ seq, rev: 0 });
    }

    const result = computeWanted(head({ seq: updates.length, updates }), {});

    assert.equal(result.farBehind, true);
});

test('a reader just inside the threshold fetches individually', () => {
    const updates = [];
    for (let seq = 1; seq <= MAX_CATCHUP; seq++) {
        updates.push({ seq, rev: 0 });
    }

    const result = computeWanted(head({ seq: updates.length, updates }), {});

    assert.equal(result.farBehind, false);
    assert.equal(result.wanted.length, MAX_CATCHUP);
});

test('an empty or malformed head is survivable', () => {
    assert.deepEqual(computeWanted({}, {}).wanted, []);
    assert.deepEqual(computeWanted({ updates: null, removed: null }, {}).wanted, []);
    assert.deepEqual(computeWanted(null, {}).wanted, []);
});

test('the catch-up threshold can be overridden for testing', () => {
    const result = computeWanted(
        head({ seq: 3, updates: [{ seq: 3, rev: 0 }, { seq: 2, rev: 0 }, { seq: 1, rev: 0 }] }),
        {},
        2,
    );

    assert.equal(result.farBehind, true);
});
