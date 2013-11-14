<?php
event::bind('launch')->to('controller','respond')->core();
event::bind('route_found')->to('controller','handle_response')->core();
event::bind('done')->to('controller','output')->core();