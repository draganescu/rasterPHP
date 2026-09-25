<?php
// By default the launch event is handled by the single controller
event::bind('launch')->to('controller','respond')->core();
// The api model executes a model's method before loading a view
// therefore api calls can be viewless.
// To end the execution for an api call just exit;
event::bind('finding_route')->to('api','load');
// The MCP endpoint (/mcp) lets agents read and edit CMS content.
// It is off unless an mcp_token is configured, see AGENTS.md
event::bind('finding_route')->to('mcp','http');
event::bind('finding_route')->to('cms','route');
// If there is no api call then the single controller will start controlling
event::bind('route_set')->to('cms','setup');
// Accounts: print.if.logged_in and friends, protected pages
event::bind('route_set')->to('authentication','setup');
// The CMS editing toolbar for logged in editors
event::bind('before_output')->to('cms','inject_toolbar');
// Alerts (<!-- print.validation.alert('name') -->) show or hide at the end
event::bind('before_output')->to('validation','finalize');
// Which is actually routing the URI to a view
event::bind('route_found')->to('controller','handle_response')->core();
// After the view has pulled all the model data in
// the default action is to output it to the browser
event::bind('done')->to('controller','output')->core();
// And if we log to browser's console, append that to the output
event::bind('done')->to('log','output')->core();