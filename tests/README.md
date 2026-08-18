# Tests

Two suites run here, and a third that can't.

```sh
ddev start          # a PHP 8.2 container; the repo has no database and needs none
ddev composer install
ddev composer test  # both suites
```

Or individually: `ddev composer test:php`, `ddev composer test:js`.

## PHP unit suite — `tests/unit`

Plain PHP, no Craft application and no database. Craft's class is required by hand in
`tests/bootstrap.php`; `Craft::t()` falls back to parameter substitution when there is no
application, which is exactly what a unit test wants.

It covers the parts of Live that are pure data: the settings model, the post state machine, and the
project-config shape of an update type. Those are the places where a mistake is *quiet* — snapshots
that stop being written, a reader that keeps polling a finished match, a setting that stops
deploying — rather than an error anyone would see.

Everything else in Live is deliberately Craft-shaped (elements, element queries, project config),
and faking that well enough to prove anything costs more than it proves. It is covered below.

## JavaScript suite — `tests/js`

Runs against the **shipped** asset files, loaded into a stubbed window, so there is no second copy
of the logic to drift out of sync.

- `feed-diff.test.mjs` — what a reader is missing, given a head file and what it already holds. New
  updates, revision bumps on updates already on screen, deletions, ordering, and the threshold at
  which a reader is too far behind to fetch one file at a time.
- `composer-queue.test.mjs` — the local publish queue, and the form encoding.

Both files have already been the source of a real bug, which is why they are the two that are
tested. The form encoding one is worth spelling out: `Craft.sendActionRequest` sends a plain object
as JSON, and PHP only expands bracket notation for form-encoded bodies — so a key posted as
`liveFields[fields][scorer]` stays one flat string on the server and every custom field value is
dropped, with no error anywhere. curl cannot catch it, because curl form-encodes by default.

## Against a real Craft

The rest needs live content and a browser. Mount the plugin into a Craft install as a path
repository, add a Live field to an entry type, and:

1. **Publish from the composer.** The card should appear before the request finishes, the textarea
   should clear, and the counter should move. Check `live_updates` for the row.
2. **Publish an update whose type has custom fields.** Then check `elements_sites.content` — this is
   the path that fails silently, and the card looks perfectly fine either way.
3. **Watch the snapshots.** `web/live-feed/{siteId}/{postId}-{fieldId}/` should gain a `u-<seq>.json`
   per publish and rewrite `head.json` each time. `head.json` should stay under a couple of hundred
   bytes.
4. **Load the front end in a second tab** and publish again. The update should arrive without a
   reload — but only while that tab is visible: polling pauses in a background tab by design, so
   test it with the tab in front, or the result is a false negative.
5. **Edit an update.** Its `rev` should increment, its `seq` should not, and it should stay where it
   is in the feed.
6. **Delete one.** Its file should go, and its sequence number should appear in `head.json`'s
   `removed` list.
7. **Break the network** (override `Craft.sendActionRequest` to reject) and publish. The card should
   go red with a retry button, and the update should be sitting in `localStorage`. Restore the
   network and hit retry.
8. **Publish the same `clientId` twice.** The second response should come back `duplicate: true`
   with the same update ID, and no second row.
9. **GraphQL** (Pro): grant a schema `liveupdatetypes.<uid>:read` for *some* of the update types and
   confirm the others are invisible — not merely filtered out of results, but absent from the
   schema. A schema with no Live scopes at all should not have `liveFeed` on it.
10. **Switch to Lite** and confirm the Live GraphQL queries disappear entirely.

`php craft live/snapshots/rebuild --all` should be safe to run mid-match: it overwrites in place
rather than emptying the directory first, so readers never see a hole.
