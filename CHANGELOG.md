# Release Notes for Live

## 5.0.0

Initial release.

- Live updates as their own element type, with per-type field layouts
- The Live field: drop it on any entry type to make that entry live-bloggable
- Keyboard-first composer with optimistic publishing and a local retry queue
- Per-post sequence numbers allocated under a row lock
- Static JSON snapshots — an immutable file per update plus one small `head.json`
- `<live-feed>` front-end runtime: no dependencies, appends server-rendered HTML
- Twig: `entry.<field>`, `craft.live.feed()`, `craft.live.updates()`, `craft.live.posts()`
- Pinning, key moments, and upcoming/live/paused/ended states
- Pro: editor presence, scheduled updates, server-sent events, CDN purging
- Pro: GraphQL — `liveFeed`, `liveUpdates`, `liveUpdate`, `liveUpdateCount`, and the Live field on
  its owner's type, with a schema component per update type
- Tests: PHP unit suite plus a JavaScript suite run against the shipped asset files
