<?php
// /lab/color/red renders lab.html; the model reads util::param('color')
controller::route('lab/color')->to('lab');
// /specials shows the menu under another address
controller::route('specials')->to('menu');
