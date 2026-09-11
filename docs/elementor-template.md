# The Forum Pages template

Forum Pages are edited under **Forum Pages** in wp-admin, not in Elementor. Elementor
draws the page around them.

Build this once, in Elementor > Templates > Theme Builder:

1. Add a **Single** template and set its condition to **Forum Pages**.
2. Put the site header at the top and the site footer at the bottom, the same ones every
   other page uses.
3. Between them, add one **Shortcode** widget containing `[forum_page]`, full width, with no
   padding of its own.

That is the whole template. Every Forum Page — this one and the next — uses it, so the
sections only ever get placed once.

Until the template exists, a Forum Page still renders its sections on its own. Once it
exists, the template is what draws them. One thing to know about that fallback: the theme
also prints the record's own title above the sections, so the page briefly has two
headings. The template replaces that, which is the point of building it.

## Or place one page yourself

Each Forum Page also has its own shortcode, shown in the **Shortcode** column of the Forum
Pages list — `[forum_page id="12"]`, say. Paste it into a Shortcode widget on any Elementor
page and that Forum Page's sections appear there, header and footer left to the host page.
The page has to be published first; a draft shows nothing to visitors.
