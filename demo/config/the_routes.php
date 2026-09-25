<?php
// /lab/color/red renders lab.html; the model reads util::param('color')
controller::route('lab/color')->to('lab');
// /specials shows the menu under another address (a page of its own)
controller::route('specials')->to('menu');
// /print/menu is the menu in the print theme (views/print/menu.html)
controller::route('print/menu')->to('menu')->from('print');
