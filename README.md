# ntrnllnk—Automatic Internal Linking for WordPress

[![Build status](https://github.com/j9t/ntrnllnk/workflows/Tests/badge.svg)](https://github.com/j9t/ntrnllnk/actions) [![GitHub Sponsors](https://badgen.net/static/Support/Open%20Source/cyan)](https://github.com/sponsors/j9t)

ntrnllnk is a WordPress plugin for automatic internal linking. It finds related posts automatically—without any manual input—and lists them after each post under “Further reading.” It also links mentions of other posts’ subjects within the text. It supports German and English content.

## Usage

Install and activate the plugin. A minute after activation, it computes related posts for all published posts; from then on, it keeps them up to date by itself. Its settings are under _Settings_ → _ntrnllnk_.

ntrnllnk appends its list to the content of single posts:

```html
<section class="ntrnllnk"><h2>Further reading</h2><ul><li><a href="…">…</a></li>…</ul></section>
```

The list includes only posts related closely enough (see “Scores”), so it may be shorter than set, or missing.

It ships without styles, so that the list looks like the rest of the content; `.ntrnllnk` is there to style it. To change the markup, use the `ntrnllnk_html` filter, which receives the HTML, the related posts, and the ID of the post they relate to.

### Placement

By default, the list follows the content, as part of it. If other plugins append things, too, like ratings or share buttons, “Priority” decides the order: the lower, the further up the list shows.

To place the list yourself, set “Placement” to “Manual,” and use either

* the `[ntrnllnk]` shortcode, in a post’s content, or
* `ntrnllnk_render()`, in a theme template; it outputs the list of the current post, or of the post whose ID it receives.

The shortcode also works with automatic placement: ntrnllnk then doesn’t append the list to that post.

### In-Content Links

Besides listing related posts, ntrnllnk links the first mention of another post’s subject in the text—for example, “Agatha Christie” in a post about crime novels to the post “Agatha Christie in the Right Order.” It adds at most three such links per post (“Maximum per post”), and at most one per paragraph (or list item), so that they spread out. It leaves headings, existing links, code, tables, and figures (like images with their captions) alone.

The text never links to the same post twice: ntrnllnk links each post once, and not at all if the text already links to it, in whatever form (like `/?p=123`). The list of related posts doesn’t count here, so a post may appear in both.

What counts as a post’s subject comes from its title: names and other runs of capitalized words, of two words or more (“Agatha Christie,” “Herr der Ringe,” “Jennifer L. Armentrout”; single words are too often common nouns, especially in German), that the post’s own text mentions at least three times. If several titles contain the same phrase—like an author’s overview and reading-order posts—it goes to the post whose text mentions it most; on a tie, to the newer post. Matching is case-sensitive and includes the genitive (“Agatha Christies,” “Agatha Christie’s”).

In-content links are added when a post is shown, not saved to it, so turning them off removes them all. To keep particular links out, use “Excluded phrases” or “Excluded posts”; for more control, the `ntrnllnk_phrases` filter receives all phrases, with the IDs of the posts they link to, and the ID of the post being shown, to remove, add, or redirect phrases.

### Settings

The settings page, under Settings → ntrnllnk, needs the `manage_options` capability (administrators, by default).

| Setting | Default | Description |
|---|---|---|
| **Related Posts** | | |
| Number of related posts | 5 | Maximum number of related posts (up to 20); 0 turns the list off |
| Heading | “Further reading” (translated) | Heading of the list |
| Heading level | h2 | Heading level (h2–h6), or “Automatic” for the highest level in the post’s content (or h2 without headings), so that the list sits at the level of the content’s top sections |
| Minimum score | 0.04 | How closely posts need to be related (0–1) to show; posts with fewer matches show fewer related posts, or no list at all (see “Scores”) |
| Debugging | Off | Whether to show scores of related posts, and posts below “Minimum score,” to logged-in users (contributors and above; see “Scores”) |
| **In-Content Links** | | |
| In-content links | On | Whether to link mentions of other posts’ subjects in the text |
| Maximum per post | 3 | Maximum number of in-content links per post |
| Excluded phrases | None | Phrases never to link, one per line, e.g., “Happy End” and “Miss Marple” |
| Excluded posts | None | IDs of posts whose content gets no in-content links, separated by commas |
| Class | Off | Whether in-content links get the class `ntrnllnk-inline` (links in the list can be selected with `.ntrnllnk a`) |
| **Advanced** | | |
| Post types | Posts | Post types to relate and show related posts for |
| Placement | After the content | “After the content” to append the list to the content, “Manual” to place it yourself |
| Priority | 20 | With placement after the content, when the list gets appended to the content, relative to other plugins |
| URLs | Absolute | Absolute URLs, which work wherever the content goes (feeds, REST API, email), or root-relative ones (`/…`) |
| Language | Detect per post | Language of the content: detected per post, or German or English for all posts |
| Weight of words | 0.6 | How much shared words count, compared with shared links, which get the rest (0.4 by default) |

### Report

The report, under _Tools_ → _ntrnllnk_, shows for each post:

* **Related posts:** its list, with scores; posts below “Minimum score,” which visitors don’t see, are collapsed below it
* **In-content links:** the links ntrnllnk adds to its text, as phrase and target
* **Listed by:** how many other posts’ lists include it

“No list” and “Never listed” filter for posts without a list and posts no list includes—candidates for better linking. In-content links aren’t stored, so the report works them out for the 20 posts on each page as it opens; depending on what other plugins do with the content, this may take a moment. Like the settings page, the report needs the `manage_options` capability.

### When Changes Apply

**To apply changed settings, save them; a rebuild starts with the next page view, which is the settings page reloading after saving.** It takes a few seconds on most sites (see “Performance”). Under “Rebuild,” the settings page shows whether one is running and when the last one finished; “Rebuild Now” starts one right away, without changing anything.

ntrnllnk works out related posts in the background, in rebuilds, and stores them; that’s why settings take effect at different times:

* **Display settings**—“Heading,” “Heading level,” “Placement,” “Priority,” “URLs,” and all settings under “In-Content Links”—apply right away, from the next page view on. So does lowering “Number of related posts,” or setting it to 0.
* **Ranking settings**—“Number of related posts,” “Minimum score,” “Debugging,” “Post types,” “Language,” and “Weight of words”—decide which posts are related, and apply after the rebuild that saving them starts.

Posts trigger rebuilds, too, a minute after they get published, unpublished, or deleted, or after their title, content, date, password, categories, or tags change. The subjects for in-content links come from these rebuilds as well. Besides, every site gets one rebuild a day.

Rebuilds run via WP-Cron, which runs on page views; if the site runs WP-Cron [via the system’s cron](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/) instead, how soon a rebuild starts depends on how often that cron runs. And if a page cache (from a caching plugin, the host, or a CDN) is in front of the site, it keeps showing the old lists until it gets cleared.

## How It Works

ntrnllnk compares every published post with every other, using two signals:

1. **Words** from title, headings, and body text (the title counting three times, headings twice), plus the post’s categories and tags (each counting like a title word); stopwords are dropped, and words are reduced to a simple stem, so that “book” and “books” (or German “Buch” and “Bücher”) match
2. **Links:** posts that link to the same targets (ignoring “www.,” query strings, and fragments) likely cover the same things—for a book blog, two posts linking to the same book

Each signal is a [TF-IDF](https://en.wikipedia.org/wiki/Tf%E2%80%93idf) vector, compared by cosine similarity. Rare features count more than common ones, and features that all posts share—site-wide boilerplate, a link on every post, a catch-all category—don’t count at all. Neither do features in more than 500 posts, which, on large sites, say little but would slow down the comparison considerably. The score is the weighted sum of both similarities, between 0 and 1; ties go to the newer post.

### Scores

Scores tend to be small: Two posts on the same subject often score around 0.05–0.1, and only near-duplicates get close to 1. They also depend on the site—the more varied its topics, the lower they are overall—so a good “Minimum score” on one site may not suit another.

“Minimum score” affects only the list of related posts, not in-content links. Raising it drops weak matches, so lists get shorter, and posts without strong matches lose their list. As an example, on one German book blog with about 150 posts, entries below 0.03 were mostly unrelated, those between 0.03 and 0.04 mixed, and most above 0.04 fitting; at 0.04, 11 of the posts had no list, and those with one had about 4 entries. If your lists show unrelated posts, raise “Minimum score”; if fitting posts are missing, lower it. Even at 0, a post can be without a list, if it shares no words, links, or terms with any other post that count.

To see where to set it, turn on “Debugging”: Logged-in users (contributors and above) then see each listed post’s score, and the share of each signal—like `[score: 0.052 – words 0.031, links 0.021]`. The list then also includes posts that missed “Minimum score,” down to half of it, struck through (`<del>`), so that posts without a list for visitors may show one. Visitors and other users see neither. Some page caches store pages for logged-in users, too; then, turn “Debugging” off when done, or check that the cache leaves these users out. The debugging data comes in `.ntrnllnk-debug` to style it.

Stopwords and stems depend on the language. With “Language” set to “Detect per post,” ntrnllnk counts German and English stopwords in each post and goes with the clear winner; if there is none, it uses the site language. Content in other languages works, too, only less precisely: Words are compared as they are.

Related posts are stored as post meta, the subjects for in-content links as an option; both are refreshed in rebuilds (see “When Changes Apply”). A new post can change every other post’s list, so ntrnllnk always recomputes all of them, in batches, with the memory limit raised to WordPress’s `WP_MAX_MEMORY_LIMIT` (adjustable via the `ntrnllnk_memory_limit` filter). When showing the list, it checks again that each related post is still published.

Uninstalling (deleting) the plugin removes its post meta, options (including its settings), and scheduled events; deactivating it only stops rebuilds, and keeps the data.

### Performance

Visitors don’t notice the size of a site: Showing the list takes a few database queries, and in-content links take lookups only for subjects the post’s text mentions.

The rebuild does notice it, as it compares all posts with each other for the list. With posts of around 600 words, measured with `composer bench` on a fast computer—servers, especially shared hosting, may be slower:

| Posts | Time | Memory |
| --- | --- | --- |
| 1,000 | 2 seconds | 70 MB |
| 5,000 | 10 seconds | 290 MB |
| 10,000 | 25 seconds | 540 MB |

Up to a few thousand posts, the rebuild fits into the 256 MB that WordPress allows by default. Beyond about 4,000 posts, it may need more: Raise `WP_MAX_MEMORY_LIMIT` (in wp-config.php), or the limit for ntrnllnk alone, via the `ntrnllnk_memory_limit` filter. If PHP’s time limit (`max_execution_time`) is low, a rebuild may also run out of time; then, it doesn’t update related posts, and the previous ones stay. On large sites, it helps to run WP-Cron [via the system’s cron](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/) rather than on page views.

Without the list, the rebuild has hardly anything to do: With “Number of related posts” set to 0, it only finds the subjects for in-content links, which, for 10,000 posts, takes about a second and less than 100 MB. In-content links, in turn, cost little, so turning them off doesn’t make the rebuild noticeably faster.

## Development

Requires PHP 8.4+ and [Composer](https://getcomposer.org/) (the plugin itself runs on PHP 8.1+):

```shell
composer install   # Also enables the pre-commit hook
composer lint      # WordPress Coding Standards, PHP compatibility, PHPStan
composer test      # PHPUnit
composer build     # Plugin files into dist/
composer bench     # Time and memory of a rebuild
```

The classes in `src/` other than `Plugin.php`, `Admin.php`, and `Report.php` don’t call WordPress. `Plugin.php` connects them to WordPress, `Admin.php` adds the settings page, and `Report.php` the report; their tests simulate WordPress with [Brain Monkey](https://github.com/Brain-WP/BrainMonkey).

### Building

`composer build` puts what belongs on a server into `dist/`: the plugin folder, `dist/ntrnllnk/`, to copy into `wp-content/plugins/`, and `dist/ntrnllnk.zip`, to upload via _Plugins_ → _Add New Plugin_ → _Upload Plugin_. It builds from the working copy, including uncommitted changes, and leaves out what `.gitignore` ignores and what `.gitattributes` marks `export-ignore`. It then checks the result: the main plugin file is there, no development files are, and all PHP files parse.

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