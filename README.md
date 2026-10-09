# ntrnllnk—Automatic Internal Linking for WordPress

[![Build status](https://github.com/j9t/ntrnllnk/workflows/Tests/badge.svg)](https://github.com/j9t/ntrnllnk/actions) [![GitHub Sponsors](https://badgen.net/static/Support/Open%20Source/cyan)](https://github.com/sponsors/j9t)

ntrnllnk is a WordPress plugin for automatic internal linking. It finds related posts automatically—without any manual input—and lists them after each post under “Further reading.” It also links mentions of other posts’ subjects within the text. It supports German and English content.

## Usage

Install and activate the plugin. A minute after activation, it computes related posts for all published posts; from then on, it keeps them up to date by itself.

ntrnllnk appends its list to the content of single posts:

```html
<section class="ntrnllnk"><h2>Further reading</h2><ul><li><a href="…">…</a></li>…</ul></section>
```

The list includes only posts that are related closely enough, as set by `score_min` (see “Scores”), so it may be shorter than `count`. A post without any such posts gets no list at all.

It ships without styles, so that the list looks like the rest of the content; `.ntrnllnk` is there to style it. With `links_class`, in-content links get the class `ntrnllnk-inline`, to style or track them without touching posts. To change the markup, use the `ntrnllnk_html` filter, which receives the HTML, the related posts, and the ID of the post they relate to.

### Placement

By default, the list follows the content, as part of it (`placement` `'auto'`). Other plugins may append things to the content, too, like ratings or share buttons; `priority` decides the order: the lower, the earlier ntrnllnk adds its list, and the further up it shows. (WordPress’s `the_content` filter runs in order of priority; ntrnllnk uses 20.)

To place the list yourself, set `placement` to `'manual'`, and use either

* the `[ntrnllnk]` shortcode, in a post’s content, or
* `ntrnllnk_render()`, in a theme template; it outputs the list of the current post, or of the post whose ID it receives.

The shortcode also works with automatic placement: ntrnllnk then doesn’t append the list to that post.

### In-Content Links

Besides listing related posts, ntrnllnk links the first mention of another post’s subject in the text—for example, “Agatha Christie” in a post about crime novels to the post “Agatha Christie in the Right Order.” It adds at most `links_inline_max` such links per post, and at most one per paragraph (or list item), so that they spread out. It leaves headings, existing links, code, tables, and figures (like images with their captions) alone.

The text never links to the same post twice: ntrnllnk links each post once, and not at all if the text already links to it, in whatever form (like `/?p=123`). The list of related posts doesn’t count here, so a post may appear in both.

What counts as a post’s subject comes from its title: names and other runs of capitalized words, of two words or more (“Agatha Christie,” “Herr der Ringe,” “Jennifer L. Armentrout”; single words are too often common nouns, especially in German), that the post’s own text mentions at least three times. If several titles contain the same phrase—like an author’s overview and reading-order posts—it goes to the post whose text mentions it most; on a tie, to the newer post. Matching is case-sensitive and includes the genitive (“Agatha Christies,” “Agatha Christie’s”).

In-content links are added when a post is shown, not saved to it, so turning them off (with `links_inline` set to `false`) removes them all. To keep particular links out instead, exclude phrases with `links_inline_exclude_phrases` or posts with `links_inline_exclude_posts`; for more control, the `ntrnllnk_phrases` filter receives all phrases, with the IDs of the posts they link to, and the ID of the post being shown, to remove, add, or redirect phrases. All of this applies immediately.

To use in-content links without the list, set `count` to `0`; this also saves most of the work in the background (see “Performance”).

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
| `count` | `5` | Maximum number of related posts; `0` turns the list off |
| `heading` | “Further reading” (translated) | Heading of the list |
| `heading_level` | `2` | Heading level (2–6), or `'auto'` for the highest level in the post’s content (or 2 without headings), so that the list sits at the level of the content’s top sections |
| `urls` | `'absolute'` | `'absolute'` for full URLs, which work wherever the content goes (feeds, REST API, email), or `'relative'` for root-relative URLs (`/…`) |
| `placement` | `'auto'` | `'auto'` to append the list to the content, `'manual'` to place it yourself |
| `priority` | `20` | With automatic placement, when the list gets appended to the content, relative to other plugins |
| `links_inline` | `true` | Whether to link mentions of other posts’ subjects in the text |
| `links_inline_max` | `3` | Maximum number of in-content links per post |
| `links_inline_exclude_phrases` | `[]` | Phrases never to link, e.g., `['Happy End', 'Miss Marple']` |
| `links_inline_exclude_posts` | `[]` | IDs of posts whose content gets no in-content links, e.g., `[123, 456]` |
| `links_class` | `false` | Whether in-content links get the class `ntrnllnk-inline` (links in the list can be selected with `.ntrnllnk a`) |
| `language` | `'auto'` | Language of the content: `'auto'` detects it per post, `'de'` or `'en'` sets it for all posts |
| `weights` | `['words' => 0.6, 'links' => 0.4]` | Weights of the signals |
| `score_min` | `0.04` | Minimum score (0–1) for a post to count as related; posts with fewer matches show fewer related posts, or no list at all |
| `debug` | `false` | Whether to show logged-in users who can edit posts (contributors and up) the scores behind the list, and posts that just missed `score_min` |

### When Changes Apply

**To apply changed settings, open any page of the site, wait at least a minute, and open a page again** (bypassing any page cache, as when logged in); a few seconds later, posts show their new lists. (If the site runs WP-Cron via the system’s cron, the second page view isn’t needed, but the wait depends on how often that cron runs.)

ntrnllnk doesn’t work out related posts when showing a post, but in the background, in a rebuild, and stores them. That’s why settings take effect at different times:

* **Display settings**—`heading`, `heading_level`, `urls`, `placement`, `priority`, `links_class`, and all `links_inline…` settings—apply right away, from the next page view on. So does lowering `count`, or setting it to `0`.
* **Ranking settings**—`post_types`, `count`, `language`, `weights`, `score_min`, and `debug`—decide which posts are related, and apply after a rebuild. ntrnllnk notices when they change and schedules one: A minute after the first page view with the new settings, the next page view starts the rebuild, which takes a few seconds on most sites (see “Performance”); then, the new lists show.

Posts trigger rebuilds, too: when they get published, unpublished, or deleted, or when their title, content, date, password, categories, or tags change. The subjects for in-content links come from these rebuilds as well. Besides, every site gets one rebuild a day.

Rebuilds run via WP-Cron, which runs on page views, so on a quiet site they may wait for the next visitor. [WP Crontrol](https://wordpress.org/plugins/wp-crontrol/) shows whether one is pending (`ntrnllnk_rebuild`) and runs it on request; so does WP-CLI, with `wp cron event run ntrnllnk_rebuild`. And if a page cache (from a caching plugin, the host, or a CDN) is in front of the site, it keeps showing the old lists until it gets cleared.

## How It Works

ntrnllnk compares every published post with every other, using two signals:

1. **Words** from title, headings, and body text (the title counting three times, headings twice), plus the post’s categories and tags (each counting like a title word); stopwords are dropped, and words are reduced to a simple stem, so that “book” and “books” (or German “Buch” and “Bücher”) match
2. **Links:** posts that link to the same targets (ignoring “www.,” query strings, and fragments) likely cover the same things—for a book blog, two posts linking to the same book

Each signal is a [TF-IDF](https://en.wikipedia.org/wiki/Tf%E2%80%93idf) vector, compared by cosine similarity. Rare features count more than common ones, and features that all posts share—site-wide boilerplate, a link on every post, a catch-all category—don’t count at all. Neither do features in more than 500 posts, which, on large sites, say little but would slow down the comparison considerably. The score is the weighted sum of both similarities, between 0 and 1; ties go to the newer post.

### Scores

Scores tend to be small: Two posts on the same subject often score around 0.05–0.1, and only near-duplicates get close to 1. They also depend on the site—the more varied its topics, the lower they are overall—so a good `score_min` on one site may not suit another.

`score_min` affects only the list of related posts, not in-content links. Raising it drops weak matches, so lists get shorter, and posts without strong matches lose their list. As an example, on one German book blog with about 150 posts, entries below 0.03 were mostly unrelated, those between 0.03 and 0.04 mixed, and most above 0.04 fitting; at 0.04, 11 of the posts had no list, and those with one had about 4 entries. If your lists show unrelated posts, raise `score_min`; if fitting posts are missing, lower it. Even at 0, a post can be without a list, if it shares no words, links, or terms with any other post that count.

A changed `score_min` applies after the next rebuild, about a minute later (see “When Changes Apply”).

To see where to set it, turn on `debug`: Logged-in users who can edit posts then see each listed post’s score, and the share of each signal—like `[score: 0.052 – words 0.031, links 0.021]`. The list then also includes posts that missed `score_min`, down to half of it, struck through (`<del>`), so that posts without a list for visitors may show one. Visitors and other users see neither. Some page caches store pages for logged-in users, too; then, turn `debug` off when done, or check that the cache leaves these users out. The debugging data comes in `.ntrnllnk-debug` to style it.

Stopwords and stems depend on the language. With `language` set to `'auto'`, ntrnllnk counts German and English stopwords in each post and goes with the clear winner; if there is none, it uses the site language. Content in other languages works, too, only less precisely: Words are compared as they are.

Related posts are stored as post meta, the subjects for in-content links as an option; both are refreshed in rebuilds (see “When Changes Apply”). A new post can change every other post’s list, so ntrnllnk always recomputes all of them, in batches, with the memory limit raised to WordPress’s `WP_MAX_MEMORY_LIMIT` (adjustable via the `ntrnllnk_memory_limit` filter). When showing the list, it checks again that each related post is still published.

Uninstalling (deleting) the plugin removes its post meta, options, and scheduled events; deactivating it only stops rebuilds, and keeps the data.

### Performance

Visitors don’t notice the size of a site: Showing the list takes a few database queries, and in-content links take lookups only for subjects the post’s text mentions.

The rebuild does notice it, as it compares all posts with each other for the list. With posts of around 600 words, measured with `composer bench` on a fast computer—servers, especially shared hosting, may be slower:

| Posts | Time | Memory |
| --- | --- | --- |
| 1,000 | 2 seconds | 70 MB |
| 5,000 | 10 seconds | 290 MB |
| 10,000 | 25 seconds | 540 MB |

Up to a few thousand posts, the rebuild fits into the 256 MB that WordPress allows by default. Beyond about 4,000 posts, it may need more: Raise `WP_MAX_MEMORY_LIMIT` (in wp-config.php), or the limit for ntrnllnk alone, via the `ntrnllnk_memory_limit` filter. If PHP’s time limit (`max_execution_time`) is low, a rebuild may also run out of time; then, it doesn’t update related posts, and the previous ones stay. On large sites, it helps to run WP-Cron [via the system’s cron](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/) rather than on page views.

Without the list, the rebuild has hardly anything to do: With `count` set to `0`, it only finds the subjects for in-content links, which, for 10,000 posts, takes about a second and less than 100 MB. In-content links, in turn, cost little, so turning them off doesn’t make the rebuild noticeably faster.

## Development

Requires PHP 8.4+ and [Composer](https://getcomposer.org/) (the plugin itself runs on PHP 8.1+):

```shell
composer install   # Also enables the pre-commit hook
composer lint      # WordPress Coding Standards, PHP compatibility, PHPStan
composer test      # PHPUnit
composer build     # Plugin files into dist/
composer bench     # Time and memory of a rebuild
```

The classes in `src/` other than `Plugin.php` don’t call WordPress. `Plugin.php` connects them to WordPress; its tests simulate WordPress with [Brain Monkey](https://github.com/Brain-WP/BrainMonkey).

### Building

`composer build` puts what belongs on a server into `dist/`: the plugin folder, `dist/ntrnllnk/`, to copy into `wp-content/plugins/`, and `dist/ntrnllnk.zip`, to upload via Plugins → Add New Plugin → Upload Plugin. It builds from the working copy, including uncommitted changes, and leaves out what `.gitignore` ignores and what `.gitattributes` marks `export-ignore`. It then checks the result: the main plugin file is there, no development files are, and all PHP files parse.

`dist/` is generated, so don’t edit it; rebuild before every deployment.

On GitHub, every push to `main` and every pull request is built, too, with the oldest supported PHP version. The plugin zip is attached to the workflow run for 30 days.

### Benchmarking

`composer bench` measures a rebuild outside WordPress: how long building documents, ranking, and finding phrases take, and how much memory they need. It uses 2,000 generated posts by default; set another number with `composer bench -- --posts=10000`, or use a site’s posts, exported with WP-CLI (`benchmark.json` is ignored by Git):

```shell
wp post list --post_status=publish --fields=ID,post_title,post_content,post_date --format=json > benchmark.json
composer bench -- --input=benchmark.json --compare
```

`--compare` also ranks without skipping features in many posts and reports how many related posts match; `--frequency-max=1000` tries another limit than 500; `--count=0` measures a site without the list. Unlike a real rebuild, the benchmark neither strips shortcodes nor uses categories and tags.

<!-- @@ Add work reference? -->