# Changelog

All notable changes to ntrnllnk are documented in this file, which is (mostly) AI-generated and (always) human-edited. Dependency updates may or may not be called out specifically.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

* Added a “Further reading” list of related posts after the content of each post, based on shared words, links, and terms
* Added German and English support, with the language detected per post
* Added the `ntrnllnk_settings` filter for post types, count, heading, heading level, language, signal weights, and minimum score
* Added the settings `heading_level` (including `'auto'`), `urls` (absolute or relative), `placement` (automatic or manual), and `priority`
* Added the `[ntrnllnk]` shortcode and the `ntrnllnk_render()` template function for manual placement
* Added the `ntrnllnk_html` filter for the list’s markup
* Added optional in-content links (`links_inline`, `links_inline_max`), linking the first mention of another post’s subject, at most once per paragraph and never twice to the same post
* Added `links_inline_exclude_phrases`, `links_inline_exclude_posts`, and the `ntrnllnk_phrases` filter to control in-content links
* Added a German translation