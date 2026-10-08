# ntrnllnk—Internal Linking Plugin for WordPress

[![Build status](https://github.com/j9t/ntrnllnk/workflows/Tests/badge.svg)](https://github.com/j9t/ntrnllnk/actions)

ntrnllnk is a WordPress plugin for internal linking. It finds related posts automatically—without any manual input—and lists them after each post under “Further reading.” It supports German and English content.

## Usage

Install and activate the plugin. A minute after activation, it computes related posts for all published posts; from then on, it keeps them up to date by itself.

ntrnllnk appends its list to the content of single posts:

```html
<section class="ntrnllnk"><h2>Further reading</h2><ul><li><a href="…">…</a></li>…</ul></section>
```

It ships without styles, so that the list looks like the rest of the content; `.ntrnllnk` is there to style it.

### Settings

Adjust the settings with the `ntrnllnk_settings` filter, e.g., in a theme’s functions.php:

```php
add_filter(
	'ntrnllnk_settings',
	fn( array $settings ): array => array_merge(
		$settings,
		[
			'count'   => 3,
			'heading' => 'Related posts',
		]
	)
);
```

| Setting | Default | Description |
|---|---|---|
| `post_types` | `['post']` | Post types to relate and show related posts for |
| `count` | `5` | Maximum number of related posts |
| `heading` | “Further reading” (translated) | Heading of the list |
| `heading_level` | `2` | Heading level (2–6) |
| `language` | `'auto'` | Language of the content: `'auto'` detects it per post, `'de'` or `'en'` sets it for all posts |
| `weights` | `['words' => 0.6, 'links' => 0.4]` | Weights of the signals (see below) |
| `score_min` | `0.02` | Minimum score (0–1) for a post to count as related; posts with fewer matches show fewer related posts, or none |

## How It Works

ntrnllnk compares every published post with every other, using two signals:

1. **Words** from title, headings, and body text (the title counting three times, headings twice), plus the post’s categories and tags (each counting like a title word); stopwords are dropped, and words are reduced to a simple stem, so that “book” and “books” (or German “Buch” and “Bücher”) match
2. **Links:** posts that link to the same targets (ignoring “www.,” query strings, and fragments) likely cover the same things—for a book blog, two posts linking to the same book

Each signal is a [TF-IDF](https://en.wikipedia.org/wiki/Tf%E2%80%93idf) vector, compared by cosine similarity. Rare features count more than common ones, and features that all posts share—site-wide boilerplate, a link on every post, a catch-all category—don’t count at all. The score is the weighted sum of both similarities, between 0 and 1; ties go to the newer post.

Stopwords and stems depend on the language. With `language` set to `'auto'`, ntrnllnk counts German and English stopwords in each post and goes with the clear winner; if there is none, it uses the site language. Content in other languages works, too, only less precisely: Words are compared as they are.

Related posts are stored as post meta and refreshed in the background (WP-Cron) a minute after a post is published, updated, unpublished, or deleted, and daily as a safety net. A new post can change every other post’s list, so ntrnllnk always recomputes all of them. When showing the list, it checks again that each related post is still published.

Uninstalling the plugin removes its post meta and scheduled events.

## Development

Requires PHP 8.4+ and [Composer](https://getcomposer.org/) (the plugin itself runs on PHP 8.1+):

```shell
composer install   # Also enables the pre-commit hook
composer lint      # WordPress Coding Standards, PHP compatibility, PHPStan
composer test      # PHPUnit
composer build     # Plugin files into dist/
```

The classes in `src/` other than `Plugin.php` don’t call WordPress and are fully unit-tested; `Plugin.php` connects them to WordPress.

### Building

`composer build` puts what belongs on a server into `dist/`: the plugin folder, `dist/ntrnllnk/`, to copy into `wp-content/plugins/`, and `dist/ntrnllnk.zip`, to upload via Plugins → Add New Plugin → Upload Plugin. It builds from the working copy, including uncommitted changes, and leaves out what `.gitignore` ignores and what `.gitattributes` marks `export-ignore`. It then checks the result: the main plugin file is there, no development files are, and all PHP files parse.

`dist/` is generated, so don’t edit it; rebuild before every deployment.

On GitHub, every push to `main` and every pull request is built, too, with the oldest supported PHP version. The plugin zip is attached to the workflow run for 30 days.