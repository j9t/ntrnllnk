# ntrnllnk—Internal Linking Plugin for WordPress

[![Build status](https://github.com/j9t/ntrnllnk/workflows/Tests/badge.svg)](https://github.com/j9t/ntrnllnk/actions)

ntrnllnk is a WordPress plugin for internal linking. It finds related posts automatically—without any manual input—and lists them after each post under “Further reading.” Optionally, it also links mentions of other posts’ subjects within the text. It supports German and English content.

## Usage

Install and activate the plugin. A minute after activation, it computes related posts for all published posts; from then on, it keeps them up to date by itself.

ntrnllnk appends its list to the content of single posts:

```html
<section class="ntrnllnk"><h2>Further reading</h2><ul><li><a href="…">…</a></li>…</ul></section>
```

It ships without styles, so that the list looks like the rest of the content; `.ntrnllnk` is there to style it. To change the markup, use the `ntrnllnk_html` filter, which receives the HTML, the related posts, and the ID of the post they relate to.

### Placement

By default, the list follows the content, as part of it (`placement` `'auto'`). Other plugins may append things to the content, too, like ratings or share buttons; `priority` decides the order: the lower, the earlier ntrnllnk adds its list, and the further up it shows. (WordPress’s `the_content` filter runs in order of priority; ntrnllnk uses 20.)

To place the list yourself, set `placement` to `'manual'`, and use either

* the `[ntrnllnk]` shortcode, in a post’s content, or
* `ntrnllnk_render()`, in a theme template; it outputs the list of the current post, or of the post whose ID it receives.

The shortcode also works with automatic placement: ntrnllnk then doesn’t append the list to that post.

### In-Content Links

With `links_inline` enabled, ntrnllnk also links the first mention of another post’s subject in the text—for example, “Agatha Christie” in a post about crime novels to the post “Agatha Christie in the Right Order.” It adds at most `links_inline_max` such links per post, links each post only once, and leaves headings, existing links, code, tables, and figures (like images with their captions) alone. Links to posts the text already links to are skipped.

What counts as a post’s subject comes from its title: names and other runs of capitalized words, of two words or more (“Agatha Christie,” “Herr der Ringe,” “Jennifer L. Armentrout”), that appear in no other post’s title and that the post’s own text mentions at least three times. Matching is case-sensitive and includes the genitive (“Agatha Christies,” “Agatha Christie’s”).

In-content links are added when a post is shown, not saved to it, so turning them off removes them all.

### Settings

Adjust the settings with the `ntrnllnk_settings` filter, e.g., in a theme’s functions.php:

```php
add_filter(
	'ntrnllnk_settings',
	fn( array $settings ): array => array_merge(
		$settings,
		[
			'count'    => 3,
			'heading'  => 'Related posts',
			'priority' => 8,
		]
	)
);
```

| Setting | Default | Description |
|---|---|---|
| `post_types` | `['post']` | Post types to relate and show related posts for |
| `count` | `5` | Maximum number of related posts |
| `heading` | “Further reading” (translated) | Heading of the list |
| `heading_level` | `2` | Heading level (2–6), or `'auto'` for the highest level in the post’s content (or 2 without headings), so that the list sits at the level of the content’s top sections |
| `urls` | `'absolute'` | `'absolute'` for full URLs, which work wherever the content goes (feeds, REST API, email), or `'relative'` for root-relative URLs (`/…`) |
| `placement` | `'auto'` | `'auto'` to append the list to the content, `'manual'` to place it yourself (see above) |
| `priority` | `20` | With automatic placement, when the list gets appended to the content, relative to other plugins (see above) |
| `links_inline` | `false` | Whether to link mentions of other posts’ subjects in the text (see above) |
| `links_inline_max` | `3` | Maximum number of in-content links per post |
| `language` | `'auto'` | Language of the content: `'auto'` detects it per post, `'de'` or `'en'` sets it for all posts |
| `weights` | `['words' => 0.6, 'links' => 0.4]` | Weights of the signals (see below) |
| `score_min` | `0.02` | Minimum score (0–1) for a post to count as related; posts with fewer matches show fewer related posts, or none |

## How It Works

ntrnllnk compares every published post with every other, using two signals:

1. **Words** from title, headings, and body text (the title counting three times, headings twice), plus the post’s categories and tags (each counting like a title word); stopwords are dropped, and words are reduced to a simple stem, so that “book” and “books” (or German “Buch” and “Bücher”) match
2. **Links:** posts that link to the same targets (ignoring “www.,” query strings, and fragments) likely cover the same things—for a book blog, two posts linking to the same book

Each signal is a [TF-IDF](https://en.wikipedia.org/wiki/Tf%E2%80%93idf) vector, compared by cosine similarity. Rare features count more than common ones, and features that all posts share—site-wide boilerplate, a link on every post, a catch-all category—don’t count at all. The score is the weighted sum of both similarities, between 0 and 1; ties go to the newer post.

Stopwords and stems depend on the language. With `language` set to `'auto'`, ntrnllnk counts German and English stopwords in each post and goes with the clear winner; if there is none, it uses the site language. Content in other languages works, too, only less precisely: Words are compared as they are.

Related posts are stored as post meta, the subjects for in-content links as an option; both are refreshed in the background (WP-Cron) a minute after a post is published, updated, unpublished, or deleted, and daily as a safety net. A new post can change every other post’s list, so ntrnllnk always recomputes all of them. When showing the list, it checks again that each related post is still published.

Uninstalling the plugin removes its post meta, option, and scheduled events.

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