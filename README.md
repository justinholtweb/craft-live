# Live

Live blogging for Craft CMS 5. An editor types a sentence, presses ⌘↵, and it is on the site — without
saving the parent entry, without breaking the page cache, and without a PHP process per reader.

```twig
{% include 'live/_feed.twig' with { feed: entry.commentary } %}
```

That is a working live blog: server-rendered on first paint, updating itself afterwards.

## What makes it fast

A live blog is a strange kind of content. It is written forty times an hour by someone watching
something happen, and read by everyone at once. Most implementations treat each update as a content
save on the parent entry, which means the entry's whole save pipeline — revisions, drafts, search
index, cache invalidation — runs every time somebody types “corner”.

Live doesn't do that.

| | |
| --- | --- |
| **An update is its own element** | One lightweight save. No revisions, no provisional drafts, no search indexing, and the parent entry is never touched. |
| **Order comes from a sequence, not a clock** | Each update gets a per-post number allocated under a row lock, so two editors publishing in the same millisecond get 412 and 413 — and a reader that has seen 412 can ask for “everything after 412” with no clock skew in the conversation. |
| **Delivery is static files** | Each publish writes an immutable JSON file for the update and rewrites one ~180-byte `head.json`. Readers poll head; nginx or your CDN serves it without waking Craft. |
| **The page cache is never invalidated** | The HTML page can be cached for a day. Updates arrive over the top of it. That is the point. |
| **Updates arrive pre-rendered** | The JSON carries HTML rendered by your own Twig partial at publish time, so an appended update is byte-identical to a server-rendered one. There is no second implementation of the card in JavaScript. |

## Requirements

Craft CMS 5.3+, PHP 8.2+.

## Installation

```sh
composer require justinholtweb/craft-live
php craft plugin/install live
```

## Setting up

1. **Add a Live field** to any entry type. That entry can now be live-blogged; the field's input is
   the composer.
2. **Add update types** under Live → Update Types. One (“Update”) is created on install. Give each
   its own field layout — a Goal that asks for a scorer, a Photo that asks for an image.
3. **Render the feed** in your template:

```twig
{% include 'live/_feed.twig' with { feed: entry.commentary } %}
```

To take over the markup, copy `live/_update.twig` and `live/_feed.twig` into your own templates
directory. Craft looks there first, so a file you provide wins and the rest keep working.

## Writing

The composer is a plain textarea and a row of buttons, one per update type. Markdown, then ⌘↵.

- Publishing never saves the entry, so two journalists on one match cannot overwrite each other.
- An update that fails to send is kept in a local queue and retried — a dropout in a press box
  costs you nothing.
- Editing an update leaves it where it is in the feed and bumps its revision, so readers holding the
  old copy quietly get the new one.
- There is a full-screen composer at **Live → Posts** for when the entry edit screen is in the way.

## Twig

```twig
{% set feed = entry.commentary %}

{{ feed.state }}          {# upcoming | live | paused | ended #}
{{ feed.isLive }}
{{ feed.count }}
{{ feed.seq }}            {# the current sequence number #}
{{ feed.pinned }}         {# the pinned update, or null #}

{% for update in feed.updates.limit(50).all() %}
    {{ update.postedAt|date('H:i') }}
    {{ update.bodyHtml }}
    {{ update.type.name }}
{% endfor %}

{% for goal in feed.updates.type('goal').all() %}…{% endfor %}
{% for key in feed.highlights.all() %}…{% endfor %}
```

`craft.live` covers the same ground without a field handle: `craft.live.feed(entry)`,
`craft.live.updates({ type: 'goal', limit: 10 })`, `craft.live.posts('live')`, `craft.live.seq(entry)`.

Because `feed.seq` changes on every publish, it is a safe cache key:

```twig
{% cache using key "feed-#{entry.id}-#{entry.commentary.seq}" %}
```

## Editions

| | Lite | Pro |
| --- | --- | --- |
| Composer, update types, Twig, static snapshots, polling | ● | ● |
| Pinning, key moments, states | ● | ● |
| Other editors' presence and soft locks | | ● |
| Scheduled and embargoed updates | | ● |
| Server-sent events | | ● |
| CDN purging (Cloudflare, Fastly, webhook) | | ● |
| GraphQL | | ● |

## GraphQL (Pro)

A headless front end polls `liveFeed` — one indexed row — and fetches updates only when the sequence
moves. Same shape as the JavaScript client, same reasoning.

```graphql
{
  liveFeed(postId: 923) {
    seq
    state
    isLive
    count
    pinnedId
  }
}
```

Then, when `seq` has moved:

```graphql
{
  liveUpdates(postId: 923, since: 412, orderBy: "seq asc") {
    seq
    rev
    body
    postedAt
    typeHandle
    ... on goal_LiveUpdate {
      scorer
    }
  }
}
```

`liveUpdate`, `liveUpdateCount` and the field itself are there too:

```graphql
{
  entry(id: 923) {
    ... on liveMatch_Entry {
      commentary { seq state updates(limit: 20) { seq body } }
    }
  }
}
```

Ask for `html` and you get the update rendered through the site's own Twig partial — useful when the
front end wants the same markup the server would have produced. It costs a render per update, so it
is only ever produced when asked for.

Each update type is its own schema component (`liveupdatetypes.<uid>:read`), so a token can be given
the match commentary without being given the newsroom's internal feed. A type outside the schema
isn't merely filtered out of results — its GraphQL type doesn't exist for that token at all.

## Testing

```sh
ddev start && ddev composer install
ddev composer test
```

A PHP unit suite (no Craft, no database) and a JavaScript suite that runs against the shipped asset
files. See `tests/README.md`, which also lists the checks that need a real Craft install.

## Console commands

```sh
php craft live/snapshots/rebuild 123    # rewrite one post's static files
php craft live/snapshots/rebuild --all
php craft live/snapshots/collect-garbage
php craft live/scheduled/release        # publish anything whose time has come (Pro)
```

## A note on server-sent events

SSE is supported, and it is off by default. Every connected reader holds a PHP process open for as
long as they are connected, so on FPM your ceiling is `pm.max_children` — and you find out where that
ceiling is at exactly the moment a live blog goes well. Polling static JSON has no such ceiling. Turn
SSE on for an internal dashboard, not for a cup final.

## Licence

Proprietary. See `LICENSE.md`.
