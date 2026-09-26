<?php
// #The annotation grammar, as data
//
// Raster's templating is HTML comments, and the engine matches them as exact
// strings: one space after `<!--`, one before `-->`. Anything else is ignored
// at runtime, which is the mistake agents and people make most often.
//
// This file is that grammar in a form a program can read. It is the source of
// the keyword lists the inspector lints with, and
// `php bin/raster annotations --json` prints it, so an agent can check what it
// is about to write before writing it.
//
// Prose for humans is in AGENTS.md, Annotations. When the two disagree, this
// file and the code win.
return array(
	'version' => 2,

	// ##Spelling
	// The engine finds annotations by exact string. `{name}` is the keyword
	// and its reference, e.g. `print.cms.headline`.
	'spelling' => array(
		'open' => '<!-- {name} -->',
		'close' => '<!-- /{name} -->',
		'self_closing' => '<!-- {name} /-->',
		'short_close' => '<!-- /{keyword} -->',
		'note' => 'One space inside each end. No newlines, no extra spaces: <!--print.cms.x--> is ignored, and lint reports it as an error.',
		'in_scripts' => 'Inside JavaScript or CSS, /*- … /-*/ stands for <!-- … -->, so the file stays valid.',
	),

	// ##Keywords
	// - `name`: the keyword takes a `.reference` (remove does not)
	// - `block`: it wraps content and needs a closing tag
	// - `self_closing`: it may end in `/-->` instead of wrapping anything
	// - `repeats`: the content is repeated, once per row the model returns
	'keywords' => array(
		'print' => array(
			'name' => true, 'block' => true, 'self_closing' => true, 'repeats' => false,
			'what' => 'Replaced by the string the model returns. false or null keeps the content as written.',
			'examples' => array(
				'<!-- print.cms.headline -->Hello<!-- /print.cms.headline -->',
				'<!-- print.newsletter.count /-->',
				'<!-- print.@src.cms.photo --><img src="a.jpg"><!-- /print.@src.cms.photo -->',
				'<!-- print.if.logged_in -->only for members<!-- /print.if.logged_in -->',
			),
		),
		'render' => array(
			'name' => true, 'block' => true, 'self_closing' => false, 'repeats' => true,
			'what' => 'Repeated once per row the model returns. Inside, print.key is a key of the row. An empty list renders nothing; false keeps the content as written.',
			'examples' => array('<!-- render.cms.news --><h2><!-- print.title -->A post<!-- /print.title --></h2><!-- /render.cms.news -->'),
		),
		'remove' => array(
			'name' => false, 'block' => true, 'self_closing' => false, 'repeats' => false,
			'what' => 'Mock-up content. Taken out before any model runs, so models inside it are never called. Cannot be nested.',
			'examples' => array('<!-- remove --><li>Lorem</li><!-- /remove -->'),
		),
		'res' => array(
			'name' => true, 'block' => true, 'self_closing' => false, 'repeats' => false,
			'what' => 'A named fragment of this view, for dry to pull in from elsewhere.',
			'examples' => array('<!-- res.header --><header>…</header><!-- /res.header -->'),
		),
		'dry' => array(
			'name' => true, 'block' => true, 'self_closing' => true, 'repeats' => false,
			'what' => 'Inserts the res fragment of another view: dry.<view>.<fragment>.',
			'examples' => array('<!-- dry._layout.header /-->'),
		),
	),

	// ##References
	'references' => array(
		'model_method' => 'print and render take <model>.<method>, both lowercase with digits and _.',
		'row_key' => 'Inside a render block, print.<key> is a key of the current row.',
		'attribute' => 'print.@<attr>.<rest> sets that attribute on the tag it wraps; print.+<attr>.<rest> appends to it. The attribute must already be on the tag.',
		'arguments' => "Literals only: numbers, 'quoted strings', true, false, null. Nothing is evaluated: render.news.latest(3, 'sports', -1).",
		'builtin_models' => array('session', 'self', 'if'),
		'builtin_keys' => array('raster_detail_link'),
		'reserved_fields' => array('slug', 'id', 'updated_at', 'enabled', 'published_at'),
		'reserved_collections' => array('users', 'raster'),
		'reserved_note' => 'A page field may not be named after a method of the cms model either; lint reports it.',
	),

	// ##Nesting and order
	'structure' => array(
		'nesting' => 'Blocks must nest. A closing tag that crosses another block is an error.',
		'short_close' => 'A closing tag may leave out the reference, or its arguments: <!-- /render -->, or <!-- /render.cms.menu -->, closes <!-- render.cms.menu(\'order=name\') -->. It closes the innermost block still open with that keyword.',
		'order' => 'Render blocks run from the last in the file to the first, so a block nested inside another runs first. Print blocks run after every render block.',
		'unclosed' => 'An unclosed block is an error; in development the page is a 500 that lists the problems.',
	),
);
