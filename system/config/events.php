<?php
event::bind('launch')->to('controller','respond')->core();
event::bind('finding_route')->to('api','load');
event::bind('route_found')->to('controller','handle_response')->core();
event::bind('done')->to('controller','output')->core();
event::bind('done')->to('log','output')->core();