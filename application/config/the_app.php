<?php
// Application settings. These override system/config/app.php.

// The theme is the folder in application/views that holds the site's views.
// config::set('theme')->to('default');

// Token for the MCP endpoint at /mcp. The endpoint is off while this is empty.
// Prefer the RASTER_MCP_TOKEN environment variable over committing a token.
// config::set('mcp_token')->to('a-long-random-string');

// In development a view with template errors shows the errors instead of
// the page. Set to false to render anyway.
// config::set('strict_templates')->to(true);

// Items per page for CMS collections (or <name>_page_size for one collection)
// config::set('raster_page_size')->to(10);

// Pages that need an account, and the role they need (member, editor, admin)
config::set('protected')->to(array('account' => 'member'));
// After logging in or signing up, go to the account page
config::set('after_login')->to('account');
