# Changelog

All notable changes to ntrnllnk are documented in this file, which is (mostly) AI-generated and (always) human-edited. Dependency updates may or may not be called out specifically.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-09

### Added

* Added a “Further reading” list of related posts after the content of each post, based on shared words, links, and terms
* Added German and English support, with the language detected per post
* Added a settings page (Settings → ntrnllnk) for the number of related posts, heading, heading level (including automatic), minimum score, in-content links, post types, placement (automatic or manual), priority, URLs (absolute or relative), language, and the weight of words versus links
* Added the rebuild status and a “Rebuild Now” button to the settings page; saving settings that affect related posts starts a rebuild right away
* Added a report (Tools → ntrnllnk) that shows, per post, its related posts with scores, in-content links, and how many lists include it, with filters for posts without a list and posts no list includes
* Added the `[ntrnllnk]` shortcode and the `ntrnllnk_render()` template function for manual placement
* Added the `ntrnllnk_html` filter for the list’s markup
* Added in-content links (on by default, with a maximum per post), linking the first mention of another post’s subject, at most once per paragraph and never twice to the same post
* Added excluded phrases, excluded posts, and the `ntrnllnk_phrases` filter to control in-content links
* Added a German translation
* Added background rebuilds that scale to large sites: posts load in batches, ranking skips words and links in more than 500 posts, rebuilds don’t overlap, and edits trigger them only when they affect related posts
* Added the option to turn off the list (0 related posts), which also skips ranking in rebuilds, for sites that want only in-content links
* Added `composer bench`, which measures the time and memory of a rebuild, on generated or exported posts
* Added the option to give in-content links the class `ntrnllnk-inline`
* Added debugging, which shows logged-in users (contributors and above) the scores behind the list, per signal, and posts below the minimum score