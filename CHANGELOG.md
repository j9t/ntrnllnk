# Changelog

All notable changes to ntrnllnk are documented in this file, which is (mostly) AI-generated and (always) human-edited. Dependency updates may or may not be called out specifically.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.2] - 2026-10-11

### Fixed

* Stopped garbling links with non-Latin characters, which could keep related posts from matching on shared links, and posts linked that way from counting as linked already

### Changed

* Made rebuilds about twice as fast, by scoring posts in a list rather than a map, reusing the tokens of recurring words, and counting stopwords natively
* Sped up in-content links by finding posts the content already links to by permalink (however encoded) or ID (like `?p=123`), rather than by resolving every internal link, which could take a query each
* Stopped autoloading the subjects for in-content links beyond the size WordPress autoloads by default (150 KB, adjustable via the `wp_max_autoloaded_option_size` filter), so that large sites don’t load them on every request

## [1.1.1] - 2026-10-11

### Fixed

* Stopped in-content links from going into attribute values that contain “>” or lack a closing quote, where they could turn inert markup into live HTML
* Stopped in-content links from going into scripts, styles, and text areas whose content looks like tags
* Stopped the autoloader from failing fatally on unknown `Ntrnllnk\` classes, leaving them to other autoloaders

## [1.1.0] - 2026-10-10

### Fixed

* Stopped taking a title’s first word as part of a name when it’s only capitalized for starting the title (like “Is Web3”)

### Added

* Added “Single words” setting (off by default) to also link single words from titles, like product or person names
* Added “Reset to Defaults” button to the settings page, and showed each setting’s default

### Changed

* Grouped settings by feature, with less common ones collapsed under “Advanced,” and shared ones under “General”

## [1.0.0] - 2026-10-09

### Added

* Released initial version