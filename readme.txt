=== ntrnllnk ===
Contributors: j9t
Donate link: https://github.com/sponsors/j9t
Tags: internal links, related posts, linking, links, seo
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Finds related posts by their content and links, lists them after each post, and links mentions of their subjects in the text.

== Description ==

ntrnllnk links your posts automatically—without any manual input:

* **Related posts:** After each post, it lists the most closely related ones under “Further reading.”
* **In-content links:** In the text, it links the first mention of another post’s subject, like “Agatha Christie” to the post “Agatha Christie in the Right Order,” at most three times per post.

To relate posts, ntrnllnk compares their words (from titles, headings, text, categories, and tags) and the links they share. It works out related posts in the background and keeps them up to date, so visitors don’t wait for it. It supports German and English content; other languages work, too, only less precisely.

The list ships without styles, so that it looks like the rest of the content. It can also be placed manually, via the `[ntrnllnk]` shortcode or `ntrnllnk_render()`, and filters allow changing its markup and the in-content links.

A report under _Tools_ → _ntrnllnk_ shows each post’s related posts, with scores, and its in-content links, as well as posts without a list and posts no list includes.

The full documentation, including all settings and how scores work, is [on GitHub](https://github.com/j9t/ntrnllnk).

== Installation ==

1. Install the plugin via _Plugins_ → _Add New Plugin_, or upload it to wp-content/plugins/.
2. Activate it. A minute later, it computes related posts for all published posts.
3. Optionally, adjust the settings under _Settings_ → _ntrnllnk_.

== Frequently Asked Questions ==

= Why Do Some Posts Have No or Only a Short List? =

The list includes only posts related closely enough. If lists show unrelated posts, raise “Minimum score”; if fitting posts are missing, lower it. “Debugging” shows the scores to logged-in users.

= When Do Changed Settings Apply? =

Display settings apply right away. Settings that decide which posts are related apply after the rebuild that saving them starts, which takes a few seconds on most sites. If a page cache is in front of the site, it keeps showing the old lists until it gets cleared.

= Does the Plugin Work on Large Sites? =

Up to a few thousand posts, rebuilds fit into the 256 MB that WordPress allows by default. Beyond about 4,000 posts, they may need more: Raise `WP_MAX_MEMORY_LIMIT`, or the limit for ntrnllnk alone, via the `ntrnllnk_memory_limit` filter. Visitors don’t notice the size of a site.

= How Do I Keep a Particular Link Out? =

Use “Excluded phrases” or “Excluded posts.” In-content links are added when a post is shown, not saved to it, so turning them off removes them all.

= What Happens When I Delete the Plugin? =

Deleting it removes its data and settings; deactivating it only stops rebuilds and keeps the data.

== Changelog ==

See the [changelog on GitHub](https://github.com/j9t/ntrnllnk/blob/main/CHANGELOG.md).