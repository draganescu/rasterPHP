<?php
// an application event: every page gets an X-Cafe header
event::bind('before_output')->to('cafe', 'stamp');
