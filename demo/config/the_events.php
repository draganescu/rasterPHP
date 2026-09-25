<?php
// application events
event::bind('before_output')->to('cafe', 'stamp');      // every page gets X-Cafe
event::bind('done')->to('cafe', 'finish');              // a core event: X-Cafe-Done
event::bind('route_not_found')->to('cafe', 'missing');  // 404s get X-Cafe-Missing
event::bind('loading_model_secret')->to('cafe', 'deny'); // the secret model never loads
