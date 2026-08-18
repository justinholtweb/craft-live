/**
 * Live — the control panel composer.
 *
 * Everything here is built around one number: how long it takes between an editor finishing a
 * sentence and that sentence being readable on the site. So the card appears the instant they press
 * publish, the request goes off in the background, and if the network is having a bad afternoon the
 * update waits in a local queue and goes when it can. Nothing blocks on anything.
 *
 * No jQuery: the control panel has it, but a keystroke handler that runs forty times an hour on a
 * laptop tethered to a phone is not the place to spend it.
 */
(function () {
    'use strict';

    var STORAGE_PREFIX = 'live:queue:';

    function h(tag, attrs, children) {
        var el = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            if (key === 'class') {
                el.className = attrs[key];
            } else if (key === 'text') {
                el.textContent = attrs[key];
            } else if (key === 'html') {
                el.innerHTML = attrs[key];
            } else {
                el.setAttribute(key, attrs[key]);
            }
        });
        (children || []).forEach(function (child) {
            el.appendChild(child);
        });
        return el;
    }

    /**
     * Post to a Craft action.
     *
     * As FormData, always — `Craft.sendActionRequest` sends a plain object as JSON, and a JSON body
     * whose key is the literal string `liveFields[fields][scorer]` stays exactly that on the server.
     * PHP only expands bracket notation for form-encoded bodies, so custom field values arrive and
     * are quietly dropped. FormData also carries file uploads, which JSON cannot.
     */
    function toFormData(data) {
        var body = new FormData();

        Object.keys(data).forEach(function (key) {
            var value = data[key];

            if (value === null || value === undefined) {
                return;
            }

            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    body.append(key, item);
                });
            } else {
                body.append(key, value);
            }
        });

        return body;
    }

    function post(action, data, files) {
        var body = toFormData(data);

        (files || []).forEach(function (entry) {
            body.append(entry.name, entry.file, entry.file.name);
        });

        if (window.Craft && Craft.sendActionRequest) {
            return Craft.sendActionRequest('POST', action, { data: body }).then(function (response) {
                return response.data;
            });
        }

        body.append(Craft.csrfTokenName, Craft.csrfTokenValue);

        return fetch(Craft.getActionUrl(action), {
            method: 'POST',
            body: body,
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        }).then(function (r) {
            return r.json();
        });
    }

    function get(action, params) {
        return Craft.sendActionRequest('GET', action, { params: params }).then(function (response) {
            return response.data;
        });
    }

    /**
     * A publish that hasn't landed yet.
     *
     * Kept in localStorage rather than in memory, because the failure this exists for — the wifi in
     * a press box — is the same failure that makes people reload the page in frustration.
     */
    function Queue(key) {
        this.key = STORAGE_PREFIX + key;
    }

    Queue.prototype.all = function () {
        try {
            return JSON.parse(window.localStorage.getItem(this.key) || '[]');
        } catch (e) {
            return [];
        }
    };

    Queue.prototype.save = function (items) {
        try {
            window.localStorage.setItem(this.key, JSON.stringify(items));
        } catch (e) {
            /* Private browsing, a full quota — the queue degrades to in-request only. */
        }
    };

    Queue.prototype.add = function (item) {
        var items = this.all();
        items.push(item);
        this.save(items);
    };

    Queue.prototype.remove = function (clientId) {
        this.save(
            this.all().filter(function (item) {
                return item.clientId !== clientId;
            })
        );
    };

    function Composer(root) {
        this.root = root;
        this.config = JSON.parse(root.getAttribute('data-live-config'));
        this.queue = new Queue(this.config.postId + '-' + this.config.fieldId + '-' + this.config.siteId);
        this.seq = this.config.seq || 0;
        this.editingId = null;
        this.typeId = this.config.types.length ? this.config.types[0].id : null;
        this.sending = 0;
        this.knownIds = {};

        this.find();
        this.bind();
        this.loadTypeFields();
        this.startPolling();
        this.drainQueue();

        var self = this;
        Array.prototype.forEach.call(this.list.querySelectorAll('[data-update-id]'), function (card) {
            self.knownIds[card.getAttribute('data-update-id')] = true;
        });
    }

    Composer.prototype.find = function () {
        var root = this.root;
        this.form = root.querySelector('[data-live-form]');
        this.textarea = root.querySelector('[data-live-body]');
        this.titleInput = root.querySelector('[data-live-title]');
        this.fieldsHolder = root.querySelector('[data-live-fields]');
        this.list = root.querySelector('[data-live-list]');
        this.status = root.querySelector('[data-live-status]');
        this.presenceBar = root.querySelector('[data-live-presence]');
        this.publishBtn = root.querySelector('[data-live-publish]');
        this.cancelBtn = root.querySelector('[data-live-cancel]');
        this.highlightInput = root.querySelector('[data-live-highlight]');
        this.pinnedInput = root.querySelector('[data-live-pinned]');
        this.postedAtInput = root.querySelector('[data-live-postedat]');
        this.counter = root.querySelector('[data-live-count]');
    };

    Composer.prototype.bind = function () {
        var self = this;

        Array.prototype.forEach.call(this.root.querySelectorAll('[data-live-type]'), function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                self.setType(parseInt(btn.getAttribute('data-live-type'), 10));
            });
        });

        this.publishBtn.addEventListener('click', function (e) {
            e.preventDefault();
            self.submit();
        });

        if (this.cancelBtn) {
            this.cancelBtn.addEventListener('click', function (e) {
                e.preventDefault();
                self.stopEditing();
            });
        }

        // ⌘↵ / Ctrl+↵ publishes from anywhere in the composer, including from inside a custom field.
        this.root.addEventListener('keydown', function (e) {
            if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
                e.preventDefault();
                self.submit();
            } else if (e.key === 'Escape' && self.editingId) {
                e.preventDefault();
                self.stopEditing();
            }
        });

        this.list.addEventListener('click', function (e) {
            var action = e.target.closest('[data-live-action]');
            if (!action) {
                return;
            }
            e.preventDefault();
            var card = action.closest('[data-update-id]');
            var id = card && parseInt(card.getAttribute('data-update-id'), 10);
            if (!id) {
                return;
            }
            switch (action.getAttribute('data-live-action')) {
                case 'edit':
                    self.startEditing(id);
                    break;
                case 'delete':
                    self.remove(id, card);
                    break;
                case 'pin':
                    self.pin(id, card.getAttribute('data-pinned') !== '1');
                    break;
            }
        });

        Array.prototype.forEach.call(this.root.querySelectorAll('[data-live-state]'), function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                self.setState(btn.getAttribute('data-live-state'));
            });
        });

        // Best-effort: tell the server we've gone, so nobody sees a ghost in the presence bar.
        window.addEventListener('pagehide', function () {
            if (!self.config.presence) {
                return;
            }
            var url = Craft.getActionUrl('live/composer/leave');
            var body = new FormData();
            body.append('postId', self.config.postId);
            body.append('fieldId', self.config.fieldId);
            body.append('siteId', self.config.siteId);
            body.append(Craft.csrfTokenName, Craft.csrfTokenValue);
            if (navigator.sendBeacon) {
                navigator.sendBeacon(url, body);
            }
        });

        window.addEventListener('online', function () {
            self.drainQueue();
        });
    };

    // Types and custom fields
    // -------------------------------------------------------------------------

    Composer.prototype.currentType = function () {
        var id = this.typeId;
        return (
            this.config.types.filter(function (type) {
                return type.id === id;
            })[0] || null
        );
    };

    Composer.prototype.setType = function (typeId) {
        if (this.typeId === typeId) {
            return;
        }
        this.typeId = typeId;
        Array.prototype.forEach.call(this.root.querySelectorAll('[data-live-type]'), function (btn) {
            btn.classList.toggle('active', parseInt(btn.getAttribute('data-live-type'), 10) === typeId);
        });
        this.loadTypeFields();
        this.textarea.focus();
    };

    Composer.prototype.loadTypeFields = function (updateId) {
        var self = this;
        var type = this.currentType();

        if (this.titleInput) {
            this.titleInput.closest('[data-live-title-wrap]').hidden = !(type && type.hasTitleField);
        }

        if (!type || (!type.hasFields && !updateId)) {
            this.fieldsHolder.innerHTML = '';
            return Promise.resolve();
        }

        var params = {
            postId: this.config.postId,
            fieldId: this.config.fieldId,
            siteId: this.config.siteId,
            typeId: type.id,
        };

        if (updateId) {
            params.updateId = updateId;
        }

        return get('live/composer/fields', params)
            .then(function (data) {
                self.fieldsHolder.innerHTML = data.html || '';
                if (data.headHtml) {
                    Craft.appendHeadHtml(data.headHtml);
                }
                if (data.bodyHtml) {
                    Craft.appendBodyHtml(data.bodyHtml);
                }
                Craft.initUiElements(self.fieldsHolder);
                if (updateId) {
                    self.textarea.value = data.body || '';
                    if (self.titleInput) {
                        self.titleInput.value = data.title || '';
                    }
                }
            })
            .catch(function () {
                self.fieldsHolder.innerHTML = '';
            });
    };

    // Publishing
    // -------------------------------------------------------------------------

    Composer.prototype.collect = function () {
        var data = {
            postId: this.config.postId,
            fieldId: this.config.fieldId,
            siteId: this.config.siteId,
            typeId: this.typeId,
            body: this.textarea.value,
            highlight: this.highlightInput && this.highlightInput.checked ? 1 : 0,
            pinned: this.pinnedInput && this.pinnedInput.checked ? 1 : 0,
        };

        if (this.titleInput && !this.titleInput.closest('[data-live-title-wrap]').hidden) {
            data.title = this.titleInput.value;
        }

        if (this.postedAtInput && this.postedAtInput.value) {
            data.postedAt = this.postedAtInput.value;
        }

        // Custom fields are namespaced, so they come off the form wholesale. Their names carry
        // bracket notation (`liveFields[fields][handle]`) and are passed through untouched — PHP
        // expands them on the way in, so long as the body is form-encoded.
        var files = [];

        Array.prototype.forEach.call(
            this.fieldsHolder.querySelectorAll('input[name], select[name], textarea[name]'),
            function (input) {
                if ((input.type === 'checkbox' || input.type === 'radio') && !input.checked) {
                    return;
                }

                if (input.type === 'file') {
                    Array.prototype.forEach.call(input.files || [], function (file) {
                        files.push({ name: input.name, file: file });
                    });
                    return;
                }

                if (input.multiple && input.tagName === 'SELECT') {
                    data[input.name] = Array.prototype.filter
                        .call(input.options, function (o) {
                            return o.selected;
                        })
                        .map(function (o) {
                            return o.value;
                        });
                    return;
                }

                if (input.name.slice(-2) === '[]') {
                    data[input.name] = data[input.name] || [];
                    data[input.name].push(input.value);
                } else {
                    data[input.name] = input.value;
                }
            }
        );

        return { data: data, files: files };
    };

    Composer.prototype.submit = function () {
        var type = this.currentType();

        if (!type) {
            return;
        }

        var collected = this.collect();
        var data = collected.data;
        var files = collected.files;

        if (!data.body.trim() && !(data.title || '').trim() && !this.fieldsHolder.innerHTML) {
            this.flash(Craft.t('live', 'Nothing to publish yet.'), 'error');
            return;
        }

        if (this.editingId) {
            this.sendEdit(data, files);
            return;
        }

        data.clientId = 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);

        var card = this.optimisticCard(data);
        this.insertCard(card, data.clientId);
        this.reset();

        // Files can't survive a trip through localStorage, so a queued retry sends the words and
        // leaves the attachment behind rather than failing outright.
        this.queue.add(data);
        this.send(data, card, files);
    };

    Composer.prototype.send = function (data, card, files) {
        var self = this;
        this.sending++;
        this.setBusy(true);

        return post('live/composer/publish', data, files)
            .then(function (response) {
                self.queue.remove(data.clientId);

                if (!response || !response.success) {
                    self.failCard(card, (response && response.message) || Craft.t('live', 'Couldn’t publish.'), data);
                    return;
                }

                self.replaceCard(card, response.update);
                self.seq = Math.max(self.seq, response.seq || 0);
                self.setCount(response.count);
            })
            .catch(function (e) {
                // Left in the queue: this is the dropout case, and it will go on the next drain.
                self.failCard(card, self.errorFrom(e), data);
            })
            .then(function () {
                self.sending--;
                self.setBusy(self.sending > 0);
            });
    };

    Composer.prototype.sendEdit = function (data, files) {
        var self = this;
        data.updateId = this.editingId;
        this.setBusy(true);

        return post('live/composer/save', data, files)
            .then(function (response) {
                if (!response || !response.success) {
                    self.flash((response && response.message) || Craft.t('live', 'Couldn’t save.'), 'error');
                    return;
                }
                var card = self.list.querySelector('[data-update-id="' + data.updateId + '"]');
                self.replaceCard(card, response.update);
                self.stopEditing();
                self.flash(Craft.t('live', 'Update saved.'));
            })
            .catch(function (e) {
                self.flash(self.errorFrom(e), 'error');
            })
            .then(function () {
                self.setBusy(false);
            });
    };

    /**
     * Anything still in the queue goes now — on load, and whenever the browser says it is back.
     */
    Composer.prototype.drainQueue = function () {
        var self = this;
        var items = this.queue.all();

        if (!items.length) {
            return;
        }

        items.forEach(function (item) {
            // The server dedupes on clientId, so a resend of something that did land is harmless.
            var card = self.list.querySelector('[data-client-id="' + item.clientId + '"]');
            if (!card) {
                card = self.optimisticCard(item);
                self.insertCard(card, item.clientId);
            }
            self.send(item, card);
        });
    };

    // Cards
    // -------------------------------------------------------------------------

    Composer.prototype.optimisticCard = function (data) {
        var type = this.currentType() || {};

        return h('div', {
            class: 'live-card live-card--pending',
            'data-client-id': data.clientId,
            html:
                '<div class="live-card__meta"><span class="live-card__type">' +
                Craft.escapeHtml(type.name || '') +
                '</span><span class="live-card__time">' +
                Craft.escapeHtml(Craft.t('live', 'Sending…')) +
                '</span></div><div class="live-card__body">' +
                Craft.escapeHtml(data.body || data.title || '').replace(/\n/g, '<br>') +
                '</div>',
        });
    };

    Composer.prototype.insertCard = function (card, clientId) {
        if (clientId) {
            card.setAttribute('data-client-id', clientId);
        }
        if (this.config.order === 'oldest') {
            this.list.appendChild(card);
        } else {
            this.list.insertBefore(card, this.list.firstChild);
        }
    };

    Composer.prototype.replaceCard = function (card, update) {
        if (!update) {
            return;
        }

        var wrapper = document.createElement('div');
        wrapper.innerHTML = update.card;
        var fresh = wrapper.firstElementChild;

        if (!fresh) {
            return;
        }

        if (card && card.parentNode) {
            card.parentNode.replaceChild(fresh, card);
        } else {
            this.insertCard(fresh);
        }

        this.knownIds[update.id] = true;
    };

    Composer.prototype.failCard = function (card, message, data) {
        if (!card) {
            return;
        }
        card.classList.add('live-card--failed');
        card.classList.remove('live-card--pending');
        var time = card.querySelector('.live-card__time');
        if (time) {
            time.textContent = message;
        }
        if (!card.querySelector('[data-live-retry]')) {
            var retry = h('button', {
                type: 'button',
                class: 'btn small live-card__retry',
                'data-live-retry': '1',
                text: Craft.t('live', 'Retry'),
            });
            var self = this;
            retry.addEventListener('click', function () {
                card.classList.remove('live-card--failed');
                card.classList.add('live-card--pending');
                retry.remove();
                self.send(data, card);
            });
            card.appendChild(retry);
        }
    };

    Composer.prototype.remove = function (id, card) {
        var self = this;

        if (!window.confirm(Craft.t('live', 'Delete this update?'))) {
            return;
        }

        post('live/composer/delete', {
            postId: this.config.postId,
            fieldId: this.config.fieldId,
            siteId: this.config.siteId,
            updateId: id,
        })
            .then(function (response) {
                if (response && response.success) {
                    card.remove();
                    delete self.knownIds[id];
                } else {
                    self.flash((response && response.message) || Craft.t('live', 'Couldn’t delete.'), 'error');
                }
            })
            .catch(function (e) {
                self.flash(self.errorFrom(e), 'error');
            });
    };

    Composer.prototype.pin = function (id, pinned) {
        var self = this;

        post('live/composer/pin', {
            postId: this.config.postId,
            fieldId: this.config.fieldId,
            siteId: this.config.siteId,
            updateId: id,
            pinned: pinned ? 1 : 0,
        })
            .then(function () {
                Array.prototype.forEach.call(self.list.querySelectorAll('[data-pinned="1"]'), function (card) {
                    card.setAttribute('data-pinned', '0');
                    card.classList.remove('live-card--pinned');
                });
                var card = self.list.querySelector('[data-update-id="' + id + '"]');
                if (card && pinned) {
                    card.setAttribute('data-pinned', '1');
                    card.classList.add('live-card--pinned');
                }
            })
            .catch(function (e) {
                self.flash(self.errorFrom(e), 'error');
            });
    };

    Composer.prototype.startEditing = function (id) {
        var self = this;
        this.editingId = id;
        this.root.classList.add('live-composer--editing');

        var card = this.list.querySelector('[data-update-id="' + id + '"]');
        if (card) {
            var typeId = parseInt(card.getAttribute('data-type-id'), 10);
            if (typeId) {
                this.typeId = typeId;
                Array.prototype.forEach.call(this.root.querySelectorAll('[data-live-type]'), function (btn) {
                    btn.classList.toggle('active', parseInt(btn.getAttribute('data-live-type'), 10) === typeId);
                });
            }
        }

        this.loadTypeFields(id).then(function () {
            self.textarea.focus();
        });

        this.publishBtn.textContent = Craft.t('live', 'Save changes');
        if (this.cancelBtn) {
            this.cancelBtn.hidden = false;
        }
    };

    Composer.prototype.stopEditing = function () {
        this.editingId = null;
        this.root.classList.remove('live-composer--editing');
        this.publishBtn.textContent = Craft.t('live', 'Publish');
        if (this.cancelBtn) {
            this.cancelBtn.hidden = true;
        }
        this.reset();
        this.loadTypeFields();
    };

    Composer.prototype.reset = function () {
        this.textarea.value = '';
        if (this.titleInput) {
            this.titleInput.value = '';
        }
        if (this.highlightInput) {
            this.highlightInput.checked = false;
        }
        if (this.pinnedInput) {
            this.pinnedInput.checked = false;
        }
        if (this.postedAtInput) {
            this.postedAtInput.value = '';
        }
        this.fieldsHolder.innerHTML = '';
        this.loadTypeFields();
        this.textarea.focus();
    };

    // State, polling, presence
    // -------------------------------------------------------------------------

    Composer.prototype.setState = function (state) {
        var self = this;

        post('live/composer/state', {
            postId: this.config.postId,
            fieldId: this.config.fieldId,
            siteId: this.config.siteId,
            state: state,
        })
            .then(function (response) {
                if (!response || !response.success) {
                    return;
                }
                self.config.state = response.state;
                self.root.setAttribute('data-state', response.state);
                Array.prototype.forEach.call(self.root.querySelectorAll('[data-live-state]'), function (btn) {
                    btn.classList.toggle('active', btn.getAttribute('data-live-state') === response.state);
                });
                var label = self.root.querySelector('[data-live-state-label]');
                if (label) {
                    label.textContent = response.label;
                }
            })
            .catch(function (e) {
                self.flash(self.errorFrom(e), 'error');
            });
    };

    Composer.prototype.startPolling = function () {
        var self = this;

        window.setInterval(function () {
            if (document.hidden) {
                return;
            }
            self.poll();
        }, this.config.pollInterval || 8000);

        if (this.config.presence) {
            this.heartbeat();
            window.setInterval(function () {
                self.heartbeat();
            }, this.config.presenceInterval || 20000);
        }
    };

    /**
     * Pick up what other editors have done. Only asks for what it hasn't seen.
     */
    Composer.prototype.poll = function () {
        var self = this;

        get('live/composer/feed', {
            postId: this.config.postId,
            fieldId: this.config.fieldId,
            siteId: this.config.siteId,
            since: this.seq,
        })
            .then(function (data) {
                if (!data || !data.success) {
                    return;
                }

                (data.updates || []).forEach(function (update) {
                    if (self.knownIds[update.id]) {
                        return;
                    }
                    var existing = self.list.querySelector('[data-update-id="' + update.id + '"]');
                    self.replaceCard(existing, update);
                });

                // Anything we are showing that the server no longer has was deleted by someone else.
                if (data.ids) {
                    var present = {};
                    data.ids.forEach(function (id) {
                        present[id] = true;
                    });
                    Array.prototype.forEach.call(self.list.querySelectorAll('[data-update-id]'), function (card) {
                        var id = card.getAttribute('data-update-id');
                        if (!present[id]) {
                            card.remove();
                            delete self.knownIds[id];
                        }
                    });
                }

                self.seq = Math.max(self.seq, data.seq || 0);
                self.setCount(data.count);
            })
            .catch(function () {
                /* A missed poll is not worth telling anyone about; the next one is 8 seconds away. */
            });
    };

    Composer.prototype.heartbeat = function () {
        var self = this;

        post('live/composer/heartbeat', {
            postId: this.config.postId,
            fieldId: this.config.fieldId,
            siteId: this.config.siteId,
        })
            .then(function (data) {
                if (!self.presenceBar || !data) {
                    return;
                }
                var others = data.others || [];
                if (!others.length) {
                    self.presenceBar.hidden = true;
                    return;
                }
                self.presenceBar.hidden = false;
                self.presenceBar.textContent = Craft.t('live', 'Also here: {names}', {
                    names: others
                        .map(function (o) {
                            return o.name;
                        })
                        .join(', '),
                });
            })
            .catch(function () {});
    };

    // Chrome
    // -------------------------------------------------------------------------

    Composer.prototype.setBusy = function (busy) {
        this.publishBtn.classList.toggle('loading', !!busy);
    };

    Composer.prototype.setCount = function (count) {
        if (this.counter && typeof count === 'number') {
            this.counter.textContent = count;
        }
    };

    Composer.prototype.flash = function (message, type) {
        if (window.Craft && Craft.cp) {
            if (type === 'error') {
                Craft.cp.displayError(message);
            } else {
                Craft.cp.displayNotice(message);
            }
            return;
        }
        if (this.status) {
            this.status.textContent = message;
        }
    };

    Composer.prototype.errorFrom = function (e) {
        if (e && e.response && e.response.data && e.response.data.message) {
            return e.response.data.message;
        }
        return Craft.t('live', 'Couldn’t reach the server — this update is queued.');
    };

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-live-composer]'), function (root) {
            if (root.__liveComposer) {
                return;
            }
            root.__liveComposer = new Composer(root);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.LiveComposer = Composer;
})();
