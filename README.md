# rfmedia-wp-plugin

**Reformed Forum Media** — WordPress plugin that manages podcast media (audio + video) for reformedforum.org and interfaces WordPress with the Reformed Forum media data.

## Source of truth

This repo was seeded from the **live production plugin** (reformedforum.org) on 2026-07-02. It reflects what is currently running, including the styled single-podcast player — an attractive audio player plus a YouTube/Vimeo video embed — driven by the episode post-meta:

- `rf_podcast_audio_url` — MP3 (Captivate)
- `rf_youtube_url` — YouTube video (preferred)
- `rf_vimeo_url` — Vimeo video (fallback)

## Files

- `rf-media.php` — main plugin (media post-meta, RSS enclosures, single-podcast player, taxonomies, feeds).
- `meta_box.php` — admin meta box for the `rf_*` media URL fields.

## Not tracked

The vendored third-party libraries present in the server plugin directory — `getid3/` and `elusive-icons-2.0.0/` — are referenced only by commented-out code and are intentionally not committed here (see `.gitignore`).

## Note on divergence

A separate, non-git working copy added topic-browse helpers (`rfmedia_topic_browse`, `rfmedia_topic_card`) that are **not** in production and therefore not in this seed. Merge those in as a follow-up if/when they're wanted.
