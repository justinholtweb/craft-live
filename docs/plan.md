# Live — plan

**Package** `justinholtweb/craft-live` · **namespace** `justinholtweb\live` · **handle** `live`

Live posting for Craft 5: an editor types, hits ⌘↵, and the update is on the site in well under a
second — without saving the parent entry, without busting the page cache, and without a PHP process
per reader.

## Locked decisions (2026-08-18)

1. **Updates are a custom element type** (`elements\Update`), not Matrix nested entries. A publish is
   one lightweight element save with the search index skipped, no revisions, no provisional drafts.
   Counterpress's `livereporting` module proved the Matrix route: it works, but every publish drags
   the whole entry-save pipeline behind it and the CP has to be hacked onto the Matrix field.
2. **A `Live` field binds a post.** Drop it into any entry type's field layout; that entry becomes a
   live post, the field's input *is* the composer, and Twig reads `entry.<handle>`.
3. **Delivery is static JSON + polling**, with SSE as an opt-in Pro transport. Each publish writes an
   immutable per-update JSON file plus one tiny mutable `head.json`; readers poll head (served by
   nginx/CDN, zero PHP) and fetch only what they're missing.
4. **Lite / Pro.** Lite: composer, Twig feed, polling, snapshots. Pro: presence, scheduled and
   embargoed updates, SSE, CDN purge, key-moments/pinning, GraphQL, analytics.

## Why this is fast

| Step | Cost |
| --- | --- |
| Editor hits ⌘↵ | Optimistic render, POST in the background |
| Server | one INSERT-shaped element save, search index off, revisions off |
| Sequence | atomic `seq` bump on the post's head row |
| Snapshot | `u-<seq>.json` (immutable) + `head.json` (~90 bytes) written to the web root |
| Reader | polls `head.json` off the CDN; on change, fetches the immutable delta |
| Page cache | never invalidated — the page is static, the feed hydrates over it |

The published JSON carries the update's **server-rendered HTML**, produced by the same Twig partial
the page used, so appended markup matches SSR exactly and themes need no duplicate JS templating.

## Schema

- `{{%live_updates}}` — id, postId, fieldId, siteId, typeId, seq, postedAt, pinned, highlight,
  authorId, clientId, meta
- `{{%live_posts}}` — head row per (postId, fieldId, siteId): state, seq, updateCount, pinnedUpdateId,
  startedAt, endedAt, snapshotSeq
- `{{%live_updatetypes}}` — project-config-managed update types (name, handle, icon, colour, field
  layout, title behaviour)

## Phases

- **0** Scaffold: composer, `Plugin.php`, settings, install migration, CP nav, permissions
- **1** `Update` element + `UpdateQuery` + update types (project config) + CP settings screens
- **2** `Live` field + `LiveFeed` value + head rows + `Publisher` (seq allocation, publish/edit/delete/pin)
- **3** Composer UI — CP asset bundle, optimistic queue, keyboard-first, controller endpoints
- **4** Snapshots — static JSON writer, `head.json`, rebuild command, invalidation
- **5** Frontend — Twig variable, shipped partials, `<live-feed>` web component, docs
- **6** Pro — presence, scheduled/embargoed, SSE, CDN purge, GraphQL, analytics
- **7** Tests, edition audit, README, CHANGELOG, docs site
