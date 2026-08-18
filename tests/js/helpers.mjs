import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const dir = path.dirname(fileURLToPath(import.meta.url));

/**
 * Load a shipped asset file and hand back what it exposes.
 *
 * The point is to test the file the browser actually gets, not a copy of its logic that can drift
 * away from it. Both files guard their DOM work behind element lookups, so a handful of stubs is
 * enough to load them.
 */
export function loadFeed() {
    const store = {};

    global.window = {
        customElements: { get: () => undefined, define: () => {} },
        localStorage: fakeStorage(store),
        setTimeout: () => 0,
        clearTimeout: () => {},
    };
    global.HTMLElement = class {};
    global.document = {
        addEventListener: () => {},
        readyState: 'complete',
        querySelectorAll: () => [],
        hidden: false,
    };

    delete require.cache[require.resolve(path.join(dir, '../../src/web/assets/feed/dist/live-feed.js'))];
    require(path.join(dir, '../../src/web/assets/feed/dist/live-feed.js'));

    return global.window.LiveFeedInternals;
}

export function loadComposer() {
    const store = {};

    global.window = { localStorage: fakeStorage(store) };
    global.document = { addEventListener: () => {}, readyState: 'complete', querySelectorAll: () => [] };
    global.Craft = { t: (category, message) => message };

    delete require.cache[require.resolve(path.join(dir, '../../src/web/assets/composer/dist/live-composer.js'))];
    require(path.join(dir, '../../src/web/assets/composer/dist/live-composer.js'));

    return { ...global.window.LiveComposerInternals, store };
}

export function fakeStorage(store) {
    return {
        getItem: (key) => (key in store ? store[key] : null),
        setItem: (key, value) => {
            store[key] = String(value);
        },
        removeItem: (key) => {
            delete store[key];
        },
    };
}

/** A head.json exactly as `Feeds::headPayload()` writes it. */
export function head({ seq, updates = [], removed = [], state = 'live', count = null }) {
    return {
        seq,
        state,
        count: count ?? updates.length,
        pinned: null,
        updatedAt: 1787060000,
        poll: 5,
        updates,
        removed,
    };
}

/** What a reader is holding: { [seq]: rev }, as the runtime records it. */
export function holding(pairs) {
    const revs = {};
    for (const [seq, rev] of pairs) {
        revs[seq] = String(rev);
    }
    return revs;
}
