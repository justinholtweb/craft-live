/**
 * Live — the front-end runtime.
 *
 * One custom element, no dependencies, about 6KB. It watches a tiny JSON file for a number to
 * change, and when it does it fetches the updates it hasn't got and puts them in the page. The
 * markup it inserts was rendered by the site's own Twig template at publish time, so there is no
 * second implementation of the card here and nothing to keep in sync.
 *
 * What it does *not* do is hold a connection open. A live blog that goes well is one where a hundred
 * thousand people arrive at once, and a hundred thousand held connections is a different plugin with
 * a much larger server bill. Polling a static file off a CDN costs the origin nothing.
 */
(function () {
    'use strict';

    if (window.customElements && window.customElements.get('live-feed')) {
        return;
    }

    var MAX_CATCHUP = 20;

    function parseConfig(el) {
        try {
            return JSON.parse(el.getAttribute('data-live-config') || '{}');
        } catch (e) {
            return {};
        }
    }

    function json(url, signal) {
        return fetch(url, {
            signal: signal,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        });
    }

    /**
     * Work out what a reader is missing, given a head file and what it already holds.
     *
     * Pure, and separated out on purpose: this is the whole correctness of the client. It has to
     * notice a brand-new update, a revision bump on one it already shows, and a deletion, and it has
     * to decide when a reader is so far behind that fetching one file at a time is the wrong move.
     *
     * @param head  the parsed head.json
     * @param revs  { [seq]: rev } for everything currently rendered
     * @returns { wanted: [{seq, rev}], removed: [seq], farBehind: boolean }
     */
    function computeWanted(head, revs, maxCatchup) {
        var wanted = [];
        var limit = typeof maxCatchup === 'number' ? maxCatchup : MAX_CATCHUP;

        (head && head.updates ? head.updates : []).forEach(function (item) {
            var known = revs[item.seq];

            if (known === undefined || String(known) !== String(item.rev)) {
                wanted.push(item);
            }
        });

        wanted.sort(function (a, b) {
            return a.seq - b.seq;
        });

        var removed = (head && head.removed ? head.removed : []).filter(function (seq) {
            return revs[seq] !== undefined;
        });

        return { wanted: wanted, removed: removed, farBehind: wanted.length > limit };
    }

    // Every browser with custom-element support has classes, so there is nothing to gain from an
    // ES5 shim here — and `Reflect.construct` gymnastics are a good way to break in one browser.
    function LiveFeed() {}

    LiveFeed.prototype.connectedCallback = function () {
        if (this._started) {
            return;
        }
        this._started = true;

        this.config = parseConfig(this);
        this.seq = this.config.seq || 0;
        this.revs = {};
        this.failures = 0;
        this.held = [];

        this.container = this.querySelector('[data-live-updates]') || this;
        this.pill = this.querySelector('[data-live-new]');
        this.newest = this.config.order !== 'oldest';

        var self = this;

        // Remember what the server already rendered, so a rev bump on one of them is noticed.
        Array.prototype.forEach.call(this.querySelectorAll('[data-seq]'), function (node) {
            self.revs[node.getAttribute('data-seq')] = node.getAttribute('data-rev') || '0';
        });

        if (this.pill) {
            this.pill.addEventListener('click', function () {
                self.release();
            });
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                self.tick();
            }
        });

        if (this.config.sse && 'EventSource' in window) {
            this.stream();
        }

        this.schedule(0);
    };

    LiveFeed.prototype.disconnectedCallback = function () {
        window.clearTimeout(this.timer);
        if (this.source) {
            this.source.close();
        }
    };

    /**
     * Stop entirely once the post has ended: there is nothing more coming, and a thousand tabs left
     * open overnight should not keep asking.
     */
    LiveFeed.prototype.schedule = function (delay) {
        var self = this;
        window.clearTimeout(this.timer);

        if (this.config.state === 'ended') {
            return;
        }

        var interval = (this.config.interval || 5) * 1000;
        // Back off on repeated failures, up to a minute — an origin having a bad time should not be
        // held down by every reader retrying at full speed.
        var backoff = Math.min(Math.pow(2, this.failures) * 1000, 60000);

        this.timer = window.setTimeout(
            function () {
                self.tick();
            },
            delay === 0 ? 0 : (delay || interval) + (this.failures ? backoff : 0)
        );
    };

    LiveFeed.prototype.tick = function () {
        var self = this;

        if (document.hidden) {
            this.schedule();
            return;
        }

        var url = this.config.head;

        if (!url) {
            this.schedule();
            return;
        }

        // A cache-busting parameter that only changes once per interval: every reader in the same
        // window asks for the same URL, so the CDN answers all of them from one origin fetch.
        var bucket = Math.floor(Date.now() / 1000 / (this.config.interval || 5));

        json(url + (url.indexOf('?') === -1 ? '?' : '&') + 't=' + bucket)
            .then(function (head) {
                self.failures = 0;
                self.applyHead(head);
                self.schedule();
            })
            .catch(function () {
                self.failures++;
                self.schedule();
            });
    };

    LiveFeed.prototype.applyHead = function (head) {
        if (!head) {
            return;
        }

        if (head.state && head.state !== this.config.state) {
            this.config.state = head.state;
            this.setAttribute('data-state', head.state);
            this.dispatchEvent(new CustomEvent('live:state', { detail: head, bubbles: true }));
        }

        if (typeof head.poll === 'number' && head.poll > 0) {
            this.config.interval = head.poll;
        }

        // Keep any counter in the page honest — it was rendered before these updates existed.
        if (typeof head.count === 'number') {
            Array.prototype.forEach.call(this.querySelectorAll('[data-live-count]'), function (node) {
                node.textContent = head.count;
            });
        }

        var self = this;
        var diff = computeWanted(head, this.revs);

        diff.removed.forEach(this.removeSeq.bind(this));

        if (!diff.wanted.length) {
            this.seq = Math.max(this.seq, head.seq || 0);
            return;
        }

        // Miles behind — a tab left open through a whole match. One request, not eighty.
        if (diff.farBehind && this.config.fetch) {
            this.catchUp();
            return;
        }

        var wanted = diff.wanted;

        wanted.forEach(function (item) {
            self.fetchUpdate(item);
        });
    };

    LiveFeed.prototype.fetchUpdate = function (item) {
        var self = this;

        if (!this.config.base) {
            this.catchUp();
            return;
        }

        // `r=` makes the URL change whenever the content does, so the file itself can be cached hard.
        json(this.config.base + '/u-' + item.seq + '.json?r=' + item.rev)
            .then(function (update) {
                self.render(update);
            })
            .catch(function () {
                // The snapshot may not have been written; the action endpoint always knows.
                self.catchUp();
            });
    };

    LiveFeed.prototype.catchUp = function () {
        var self = this;

        if (!this.config.fetch || this._catchingUp) {
            return;
        }

        this._catchingUp = true;

        var url =
            this.config.fetch +
            (this.config.fetch.indexOf('?') === -1 ? '?' : '&') +
            'post=' +
            this.config.post +
            '&field=' +
            this.config.field +
            '&site=' +
            this.config.site +
            '&since=' +
            this.seq;

        json(url)
            .then(function (data) {
                (data.updates || []).forEach(function (update) {
                    self.render(update);
                });
                self.seq = Math.max(self.seq, data.seq || 0);
            })
            .catch(function () {})
            .then(function () {
                self._catchingUp = false;
            });
    };

    LiveFeed.prototype.render = function (update) {
        if (!update || !update.seq) {
            return;
        }

        // All of them: a pinned update is rendered in two places, and an edit has to reach both.
        var existing = this.querySelectorAll('[data-seq="' + update.seq + '"]');
        var node = this.build(update);

        if (existing.length) {
            Array.prototype.forEach.call(existing, function (old, i) {
                var replacement = i === 0 ? node : node.cloneNode(true);
                if (i > 0) {
                    replacement.removeAttribute('id');
                }
                old.replaceWith(replacement);
            });
        } else if (this.shouldHold()) {
            this.held.push(node);
            this.showPill();
        } else {
            this.place(node);
        }

        this.revs[update.seq] = String(update.rev || 0);
        this.seq = Math.max(this.seq, update.seq);

        this.dispatchEvent(new CustomEvent('live:update', { detail: update, bubbles: true }));
    };

    LiveFeed.prototype.build = function (update) {
        var wrapper = document.createElement('div');

        if (update.html) {
            wrapper.innerHTML = update.html;
            var node = wrapper.firstElementChild;
            if (node) {
                node.setAttribute('data-seq', update.seq);
                node.setAttribute('data-rev', update.rev || 0);
                node.classList.add('live-update--new');
                return node;
            }
        }

        // No pre-rendered HTML — the template was missing or failed. Something readable is better
        // than a gap in the feed.
        var article = document.createElement('article');
        article.className = 'live-update live-update--fallback live-update--new';
        article.setAttribute('data-seq', update.seq);
        article.setAttribute('data-rev', update.rev || 0);

        var time = document.createElement('time');
        time.className = 'live-update__time';
        if (update.postedAt) {
            time.setAttribute('datetime', update.postedAt);
            time.textContent = new Date(update.postedAt).toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit',
            });
        }
        article.appendChild(time);

        if (update.title) {
            var heading = document.createElement('h3');
            heading.className = 'live-update__title';
            heading.textContent = update.title;
            article.appendChild(heading);
        }

        var body = document.createElement('div');
        body.className = 'live-update__body';
        body.textContent = update.excerpt || '';
        article.appendChild(body);

        return article;
    };

    LiveFeed.prototype.place = function (node) {
        if (this.newest) {
            this.container.insertBefore(node, this.container.firstChild);
        } else {
            this.container.appendChild(node);
        }
    };

    LiveFeed.prototype.removeSeq = function (seq) {
        Array.prototype.forEach.call(this.querySelectorAll('[data-seq="' + seq + '"]'), function (node) {
            node.remove();
        });
        delete this.revs[seq];
    };

    /**
     * Don't move the page under someone who is reading further down it. New updates wait behind a
     * button until they come back to the top.
     */
    LiveFeed.prototype.shouldHold = function () {
        if (!this.pill || !this.newest) {
            return false;
        }

        return this.container.getBoundingClientRect().top < -80;
    };

    LiveFeed.prototype.showPill = function () {
        if (!this.pill) {
            return;
        }
        this.pill.hidden = false;
        var count = this.held.length;
        this.pill.setAttribute('data-count', count);
        var label = this.pill.getAttribute('data-label') || '{n} new';
        this.pill.textContent = label.replace('{n}', count);
    };

    LiveFeed.prototype.release = function () {
        var self = this;
        this.held.forEach(function (node) {
            self.place(node);
        });
        this.held = [];
        if (this.pill) {
            this.pill.hidden = true;
        }
        this.container.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    /**
     * Server-sent events, when the site has turned them on. Polling stays running underneath as the
     * safety net — a dropped EventSource is silent, and a live blog that has quietly stopped
     * updating is worse than one that updates five seconds late.
     */
    LiveFeed.prototype.stream = function () {
        var self = this;

        try {
            this.source = new EventSource(
                this.config.sse +
                    (this.config.sse.indexOf('?') === -1 ? '?' : '&') +
                    'post=' +
                    this.config.post +
                    '&field=' +
                    this.config.field +
                    '&site=' +
                    this.config.site +
                    '&since=' +
                    this.seq
            );
        } catch (e) {
            return;
        }

        this.source.addEventListener('update', function (event) {
            try {
                self.render(JSON.parse(event.data));
            } catch (e) {}
        });

        this.source.addEventListener('reconnect', function () {
            self.source.close();
            self.stream();
        });

        this.source.addEventListener('error', function () {
            self.source.close();
            self.source = null;
        });
    };

    var LiveFeedElement = class extends HTMLElement {};

    Object.getOwnPropertyNames(LiveFeed.prototype).forEach(function (name) {
        if (name === 'constructor') {
            return;
        }
        LiveFeedElement.prototype[name] = LiveFeed.prototype[name];
    });

    window.customElements.define('live-feed', LiveFeedElement);

    // Exposed for the test suite, which exercises the diffing against the same code the browser runs.
    window.LiveFeedInternals = { computeWanted: computeWanted, MAX_CATCHUP: MAX_CATCHUP };
})();
