/*
 * Raster's in-page editor.
 *
 * The page is the editor: the CMS marks every field, list and item it
 * prints (comments like <!--raster:s 3--> … <!--raster:e 3-->), and this
 * script makes them editable where they are, in the site's own typography.
 * Its own controls live in a Shadow DOM and take their colours and fonts
 * from the page. No dependencies.
 */
(function () {
	'use strict';

	var configNode = document.getElementById('raster-editor-config');
	if (!configNode || window.RasterEditor) return;
	var C = JSON.parse(configNode.textContent);
	var reduceMotion = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
	var touch = window.matchMedia && matchMedia('(hover: none)').matches;

	// ##Words
	var T = {
		hello: 'Hi {name}', hello_anon: 'Hi there',
		welcome: 'Anything that glows can be changed. Press E or tap Edit to start.',
		edit: 'Edit', done: 'Done', editing: 'Editing', page: 'Page',
		saving: 'Saving…', saved: 'Saved', all_saved: 'All saved',
		changed: 'Changed {field}', undo: 'Undo', undone: 'Undone', nothing_to_undo: 'Nothing to undo',
		added: 'Added to {list}', deleted: 'Deleted {name}', hidden_item: 'Hid {name}', shown_item: '{name} is visible again',
		duplicated: 'Duplicated {name}', scheduled: '{name} goes live {date}', unscheduled: '{name} is live now',
		add_to: 'Add to {list}', add: 'Add', cancel: 'Cancel', details: 'Details', duplicate: 'Duplicate',
		hide: 'Hide', show: 'Show', schedule: 'Schedule', delete: 'Delete', draft: 'Hidden', goes_live: 'Goes live {date}',
		replace_photo: 'Replace photo', drop_photo: 'Drop a photo', use_photo: 'Use photo', zoom: 'Zoom',
		crop_hint: 'Drag to choose what shows', uploading: 'Uploading…', photo_changed: 'Changed the photo',
		this_page: 'This page', fields: 'On this page', lists: 'Lists', history: 'History', site_wide: 'Everywhere on the site',
		not_on_page: 'not shown in the page', show_me: 'Show me', restore: 'Restore', restored: 'Brought back the version from {when}',
		current: 'Current version', no_history: 'No earlier versions yet.', log_out: 'Log out', close: 'Close', save: 'Save',
		publish_at: 'Goes live at', publish_now: 'Now', visible: 'Visible', address: 'Address',
		error: 'That didn’t save: {error}', offline: 'Couldn’t reach the site. Your change is still in the page; try again.',
		link_prompt: 'Link address', bold: 'Bold', italic: 'Italic', link: 'Link', list: 'List', clear: 'Plain text',
		items: '{n} items', one_item: '1 item', hidden_count: '{n} hidden', empty_field: 'Empty: the template’s text shows',
		new_item: 'New', edit_mode_on: 'Editing. Click anything that glows.', edit_mode_off: 'Done editing',
		keyboard: 'E to edit · Esc to stop · ⌘Z to undo', fields_changed: '{fields}', page_fields: 'Fields', nothing_here: 'Nothing on this page comes from the CMS.'
	};
	for (var k in (C.strings || {})) T[k] = C.strings[k];
	function t(key, vars) {
		return String(T[key] || key).replace(/\{(\w+)\}/g, function (_, n) { return vars && vars[n] != null ? vars[n] : ''; });
	}
	function human(name) {
		name = String(name || '').replace(/^site_/, '').replace(/_/g, ' ');
		return name.charAt(0).toUpperCase() + name.slice(1);
	}
	var lang = C.lang || document.documentElement.lang || undefined;
	function when(date) {
		var d = new Date(String(date).replace(' ', 'T'));
		if (isNaN(d)) return String(date);
		var diff = (d - new Date()) / 1000, abs = Math.abs(diff);
		try {
			var rtf = new Intl.RelativeTimeFormat(lang, { numeric: 'auto' });
			if (abs < 60) return rtf.format(0, 'second');
			if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute');
			if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour');
			if (abs < 604800) return rtf.format(Math.round(diff / 86400), 'day');
		} catch (e) {}
		return d.toLocaleDateString(lang, { day: 'numeric', month: 'short', year: d.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' });
	}
	function day(date) {
		var d = new Date(String(date).replace(' ', 'T'));
		return isNaN(d) ? String(date) : d.toLocaleString(lang, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
	}
	function store(key, value) {
		try { if (value === undefined) return sessionStorage.getItem(key); if (value === null) sessionStorage.removeItem(key); else sessionStorage.setItem(key, value); } catch (e) { return null; }
	}

	// ##Talking to the site
	function call(method, data, file) {
		var body = new FormData();
		body.append('csrf', C.csrf);
		Object.keys(data || {}).forEach(function (key) {
			var value = data[key];
			if (value && typeof value === 'object') Object.keys(value).forEach(function (f) { body.append(key + '[' + f + ']', value[f] == null ? '' : value[f]); });
			else body.append(key, value == null ? '' : value);
		});
		if (file) body.append('image', file, file.name || 'photo.jpg');
		return fetch(C.api + method, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (response) {
			return response.text().then(function (text) {
				var json = null;
				try { json = JSON.parse(text); } catch (e) {}
				if (!response.ok || !json || json.error) throw new Error(json && json.error ? json.error : (text.slice(0, 120) || response.status));
				return json;
			});
		}, function () { throw new Error(t('offline')); });
	}

	// ##The page's own look
	function parseColor(value) {
		var m = String(value).match(/rgba?\(([^)]+)\)/);
		if (!m) return null;
		var p = m[1].split(/[\s,\/]+/).filter(Boolean).map(parseFloat);
		return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
	}
	function css(c, alpha) { return 'rgba(' + Math.round(c.r) + ',' + Math.round(c.g) + ',' + Math.round(c.b) + ',' + (alpha == null ? c.a : alpha) + ')'; }
	function mix(a, b, amount) { return { r: a.r + (b.r - a.r) * amount, g: a.g + (b.g - a.g) * amount, b: a.b + (b.b - a.b) * amount, a: 1 }; }
	function luminance(c) {
		var f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
		return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
	}
	function distance(a, b) { return Math.abs(a.r - b.r) + Math.abs(a.g - b.g) + Math.abs(a.b - b.b); }
	function readLook() {
		var root = getComputedStyle(document.documentElement), body = getComputedStyle(document.body);
		var fg = parseColor(body.color) || { r: 30, g: 30, b: 30, a: 1 };
		var bg = parseColor(body.backgroundColor);
		if (!bg || bg.a === 0) bg = parseColor(root.backgroundColor);
		if (!bg || bg.a === 0) bg = { r: 255, g: 255, b: 255, a: 1 };
		var accent = null, radius = '12px';
		var contrast = function (a, b) { var x = luminance(a), y = luminance(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
		var visible = function (el) { return !el.closest('[data-raster-host]') && el.getClientRects().length; };
		// a button's colour first, then the colour of links
		var buttons = document.querySelectorAll('.button, button, [class*=btn], input[type=submit]');
		for (var i = 0; i < buttons.length && i < 60 && !accent; i++) {
			if (!visible(buttons[i])) continue;
			var s = getComputedStyle(buttons[i]), c = parseColor(s.backgroundColor);
			if (c && c.a > 0.5 && contrast(c, bg) >= 2.2 && distance(c, fg) > 60) { accent = c; radius = s.borderRadius || radius; }
		}
		var links = document.querySelectorAll('main a, a');
		for (var j = 0; j < links.length && j < 80 && !accent; j++) {
			var lc = parseColor(getComputedStyle(links[j]).color);
			if (lc && distance(lc, fg) > 60 && contrast(lc, bg) >= 2.2) accent = lc;
		}
		if (!accent) accent = { r: 37, g: 99, b: 235, a: 1 };
		var own = function (name) { return root.getPropertyValue(name).trim(); };
		var dark = luminance(bg) < 0.25;
		var surface = mix(bg, dark ? { r: 255, g: 255, b: 255 } : { r: 255, g: 255, b: 255 }, dark ? 0.07 : 0.6);
		var h = document.querySelector('h1, h2');
		var r = parseFloat(radius);
		return {
			font: own('--raster-font') || body.fontFamily,
			heading: own('--raster-heading-font') || (h ? getComputedStyle(h).fontFamily : body.fontFamily),
			fg: own('--raster-fg') || css(fg, 1),
			muted: css(mix(fg, bg, 0.45), 1),
			bg: own('--raster-bg') || css(bg, 1),
			surface: own('--raster-surface') || css(surface, 1),
			line: css(mix(fg, bg, 0.85), 1),
			accent: own('--raster-accent') || css(accent, 1),
			accentSoft: css(accent, 0.14),
			onAccent: own('--raster-on-accent') || (luminance(accent) > 0.45 ? '#141414' : '#ffffff'),
			radius: own('--raster-radius') || (isNaN(r) ? '12px' : Math.min(Math.max(r, 6), 22) + 'px'),
			shadow: dark ? '0 10px 30px rgba(0,0,0,.45), 0 1px 2px rgba(0,0,0,.4)' : '0 12px 32px rgba(40,30,20,.16), 0 1px 3px rgba(40,30,20,.12)'
		};
	}

	// ##Finding what can be edited
	var marks = C.marks || {};
	var fields = [];   // {id, info, el, kind: 'text'|'rich'|'image', key}
	var items = {};    // item mark id -> {id, info, nodes, el, fields: []}
	var lists = [];    // {id, info, start, end, template, items: []}

	function commentsIn(root) {
		var found = {}, walker = document.createTreeWalker(root, NodeFilter.SHOW_COMMENT), node;
		while ((node = walker.nextNode())) {
			var m = /^raster:(s|e|a) (\d+)$/.exec(node.data);
			if (!m) continue;
			(found[m[2]] = found[m[2]] || {})[m[1]] = node;
		}
		return found;
	}
	function between(start, end) {
		var nodes = [];
		if (!start || !end || start.parentNode !== end.parentNode) return nodes;
		for (var n = start.nextSibling; n && n !== end; n = n.nextSibling) nodes.push(n);
		return nodes;
	}
	function meaningful(node) { return !(node.nodeType === 3 && !node.textContent.trim()) && node.nodeType !== 8; }
	function isBlock(node) {
		if (node.nodeType !== 1) return false;
		return /^(P|DIV|UL|OL|H[1-6]|BLOCKQUOTE|TABLE|SECTION|ARTICLE|FIGURE|PRE)$/.test(node.tagName);
	}
	// the element to make editable for the nodes between two marks: their
	// parent when they are all it holds, or a wrapper around them
	function region(start, end) {
		if (!start || !end || start.parentNode !== end.parentNode) return null;
		var parent = start.parentNode, inside = between(start, end);
		var outside = Array.prototype.filter.call(parent.childNodes, function (n) { return n !== start && n !== end && inside.indexOf(n) < 0 && meaningful(n); });
		if (!outside.length && parent !== document.body && !/^(MAIN|BODY|SECTION|ARTICLE|LI|TD)$/.test(parent.tagName)) return parent;
		var wrapper = document.createElement(inside.some(isBlock) ? 'div' : 'span');
		wrapper.setAttribute('data-raster-wrap', '');
		parent.insertBefore(wrapper, end);
		inside.forEach(function (n) { wrapper.appendChild(n); });
		return wrapper;
	}
	function nextElement(node) {
		for (var n = node && node.nextSibling; n; n = n.nextSibling) if (n.nodeType === 1) return n;
		return null;
	}
	function itemName(item) {
		var v = item.info.values || {};
		return v.title || v.name || v.headline || v.question || v.label || human(item.info.collection).replace(/s$/, '');
	}
	function itemState(info) {
		if (String(info.enabled) === '0') return 'draft';
		if (info.published_at && new Date(String(info.published_at).replace(' ', 'T')) > new Date()) return 'scheduled';
		return 'live';
	}

	function scan() {
		var found = commentsIn(document.body);
		Object.keys(found).forEach(function (id) {
			var info = marks[id];
			if (!info) return;
			var pair = found[id];
			if (info.kind === 'item') {
				var nodes = between(pair.s, pair.e);
				var el = nodes.filter(function (n) { return n.nodeType === 1; })[0];
				if (!el) return;
				items[id] = { id: id, info: info, start: pair.s, end: pair.e, el: el, fields: [] };
				el.setAttribute('data-raster-item', id);
				el.setAttribute('data-raster-state', itemState(info));
			} else if (info.kind === 'collection') {
				var template = null;
				between(pair.s, pair.e).forEach(function (n) { if (n.nodeType === 1 && n.tagName === 'TEMPLATE') template = n; });
				lists.push({ id: id, info: info, start: pair.s, end: pair.e, template: template, items: [] });
			}
		});
		Object.keys(found).forEach(function (id) {
			var info = marks[id], pair = found[id];
			if (!info || info.kind === 'item' || info.kind === 'collection') return;
			var field = { id: id, info: info };
			if (pair.a) {
				field.el = nextElement(pair.a);
				field.kind = field.el && field.el.tagName === 'IMG' ? 'image' : 'attr';
				field.attr = info.attr;
			} else {
				field.el = region(pair.s, pair.e);
				field.kind = (info.rich || (info.kind === 'item_field' && /<[a-z][^>]*>/i.test(field.el ? field.el.innerHTML : ''))) ? 'rich' : 'text';
			}
			if (!field.el) return;
			if (info.kind === 'item_field' || info.kind === 'item_attr') {
				field.item = items[info.item];
				if (!field.item) return;
				field.item.fields.push(field);
				field.key = 'item:' + info.item + ':' + info.field;
			} else {
				field.key = 'page:' + info.type + ':' + info.field;
			}
			field.el.setAttribute('data-raster-field', id);
			if (field.kind !== 'image' && field.kind !== 'attr') field.el.setAttribute('data-raster-edit', field.kind);
			fields.push(field);
		});
		lists.forEach(function (list) {
			Object.keys(items).forEach(function (id) {
				var item = items[id];
				if (item.info.collection === list.info.collection && list.start.compareDocumentPosition(item.el) & Node.DOCUMENT_POSITION_FOLLOWING && list.end.compareDocumentPosition(item.el) & Node.DOCUMENT_POSITION_PRECEDING) list.items.push(item);
			});
		});
		fields.forEach(function (f, i) { f.el.style.setProperty('--raster-i', Math.min(i, 30)); });
	}

	// ##Styles for the page itself (the glow, the states)
	function pageStyles(look) {
		var style = document.createElement('style');
		style.id = 'raster-editor-page';
		style.textContent = [
			':root{--raster-a:' + look.accent + ';--raster-a-soft:' + look.accentSoft + '}',
			'html.raster-editing [data-raster-edit]{cursor:text;border-radius:3px;text-decoration-line:underline;text-decoration-style:dotted;text-decoration-thickness:2px;text-underline-offset:.22em;text-decoration-color:color-mix(in srgb,var(--raster-a) 70%,transparent);transition:background-color .25s,box-shadow .25s,text-decoration-color .25s}',
			'html.raster-editing [data-raster-edit=rich]{text-decoration-line:none;box-shadow:inset 3px 0 0 color-mix(in srgb,var(--raster-a) 55%,transparent)}',
			'html.raster-editing [data-raster-edit]:hover{background-color:var(--raster-a-soft)}',
			'html.raster-editing [data-raster-edit]:focus{outline:2px solid var(--raster-a);outline-offset:4px;background-color:color-mix(in srgb,var(--raster-a) 7%,transparent);text-decoration-color:transparent;box-shadow:none}',
			'html.raster-glow [data-raster-edit],html.raster-glow img[data-raster-field]{animation:raster-breathe 1.1s ease-in-out both;animation-delay:calc(var(--raster-i,0)*45ms)}',
			'@keyframes raster-breathe{0%{background-color:transparent;box-shadow:0 0 0 0 transparent}40%{background-color:var(--raster-a-soft);box-shadow:0 0 0 6px var(--raster-a-soft)}100%{background-color:transparent;box-shadow:0 0 0 0 transparent}}',
			'html.raster-editing img[data-raster-field]{cursor:pointer;transition:filter .2s,outline-color .2s;outline:2px dashed transparent;outline-offset:3px}',
			'html.raster-editing img[data-raster-field]:hover{filter:brightness(.92);outline-color:var(--raster-a)}',
			'html.raster-editing [data-raster-item]{transition:opacity .3s,filter .3s,outline-color .2s,transform .35s;outline:2px dashed transparent;outline-offset:6px}',
			'html.raster-editing [data-raster-item]:hover,html.raster-editing [data-raster-item].raster-active{outline-color:color-mix(in srgb,var(--raster-a) 45%,transparent)}',
			'[data-raster-state=draft],[data-raster-state=scheduled]{opacity:.55;filter:saturate(.4)}',
			'[data-raster-state=draft]:hover,[data-raster-state=scheduled]:hover{opacity:.85}',
			'.raster-leaving{opacity:0!important;transform:scale(.96)!important;transition:opacity .35s,transform .35s!important}',
			'.raster-arriving{animation:raster-arrive .45s cubic-bezier(.2,.9,.3,1.3) both}',
			'@keyframes raster-arrive{from{opacity:0;transform:translateY(8px) scale(.97)}to{opacity:1;transform:none}}',
			'.raster-ghost{opacity:.6;outline:2px dashed var(--raster-a)!important;outline-offset:6px;cursor:pointer;position:relative;transition:opacity .2s}',
			'.raster-ghost:hover{opacity:.85}',
			'.raster-ghost.raster-new{opacity:1;cursor:auto}',
			'[data-raster-default]{opacity:.5}',
			'.raster-pulse{animation:raster-pulse 1.4s ease-out 1}',
			'@keyframes raster-pulse{0%{box-shadow:0 0 0 0 var(--raster-a)}100%{box-shadow:0 0 0 18px transparent}}',
			'@media (prefers-reduced-motion:reduce){html.raster-glow [data-raster-edit],.raster-arriving,.raster-pulse{animation:none!important}.raster-leaving{transition:none!important}}'
		].join('\n');
		document.head.appendChild(style);
	}

	// ##The editor's own controls, in a shadow root
	var icons = {
		pencil: '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M14 6l4 4"/>',
		copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
		eye: '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
		eyeoff: '<path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.6 6.6A17 17 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
		clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		trash: '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/>',
		camera: '<path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>',
		plus: '<path d="M12 5v14M5 12h14"/>',
		check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
		x: '<path d="M6 6l12 12M18 6L6 18"/>',
		layers: '<path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 13l9 5 9-5"/>',
		bold: '<path d="M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7z"/>',
		italic: '<path d="M10 5h8M6 19h8M14 5l-4 14"/>',
		link: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
		list: '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
		clear: '<path d="M6 6h12M12 6v13"/><path d="M4 20L20 4" opacity=".5"/>',
		history: '<path d="M4 12a8 8 0 1 0 2.3-5.6L4 8.7"/><path d="M4 4v4.7h4.7"/><path d="M12 8v4l3 2"/>'
	};
	function icon(name) { return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + icons[name] + '</svg>'; }
	function h(tag, attrs, children) {
		var el = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			if (key === 'html') el.innerHTML = attrs[key];
			else if (key === 'text') el.textContent = attrs[key];
			else if (key.slice(0, 2) === 'on') el.addEventListener(key.slice(2), attrs[key]);
			else if (attrs[key] != null && attrs[key] !== false) el.setAttribute(key, attrs[key]);
		});
		(children || []).forEach(function (c) { if (c) el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return el;
	}

	var host, shadow, ui = {};
	function chromeStyles(look) {
		return [
			':host{all:initial}',
			'[hidden]{display:none!important}',
			'.thumb{display:flex;align-items:center;gap:10px}',
			'.thumb img{width:84px;height:56px;object-fit:cover;border-radius:8px;border:1px solid ' + look.line + '}',
			'*{box-sizing:border-box}',
			'.layer{position:fixed;inset:0;pointer-events:none;z-index:2147483000;font:14px/1.4 ' + look.font + ';color:' + look.fg + ';-webkit-font-smoothing:antialiased}',
			'button{font:inherit;color:inherit;background:none;border:0;cursor:pointer;padding:0}',
			'button:focus-visible,input:focus-visible,textarea:focus-visible{outline:2px solid ' + look.accent + ';outline-offset:2px}',
			'svg{width:18px;height:18px;display:block;flex:none}',
			'.surface{background:' + look.surface + ';color:' + look.fg + ';border:1px solid ' + look.line + ';box-shadow:' + look.shadow + ';border-radius:' + look.radius + '}',
			// the dock
			'.dock{position:absolute;left:50%;bottom:calc(18px + env(safe-area-inset-bottom));transform:translateX(-50%);display:flex;align-items:center;gap:4px;padding:5px;border-radius:999px;pointer-events:auto;transition:transform .5s cubic-bezier(.2,.9,.3,1.25),opacity .3s;white-space:nowrap}',
			'.dock.hidden{transform:translate(-50%,140%);opacity:0}',
			'.who{width:30px;height:30px;border-radius:50%;display:grid;place-items:center;background:' + look.accentSoft + ';color:' + look.accent + ';font-weight:700;font-size:13px;margin-right:2px}',
			'.pill{display:inline-flex;align-items:center;gap:7px;height:32px;padding:0 14px;border-radius:999px;transition:background .2s,color .2s,transform .15s}',
			'.pill:hover{background:' + look.accentSoft + '}',
			'.pill:active{transform:scale(.96)}',
			'.pill.primary{background:' + look.accent + ';color:' + look.onAccent + ';font-weight:600}',
			'.pill.primary:hover{filter:brightness(1.08)}',
			'.status{display:inline-flex;align-items:center;gap:6px;padding:0 10px;color:' + look.muted + ';font-size:13px;min-width:70px}',
			'.dot{width:7px;height:7px;border-radius:50%;background:#3aa86b;transition:background .2s}',
			'.dot.busy{background:' + look.accent + ';animation:blink 1s infinite}',
			'@keyframes blink{50%{opacity:.3}}',
			'.bubble{position:absolute;left:50%;bottom:calc(76px + env(safe-area-inset-bottom));transform:translateX(-50%);max-width:min(380px,calc(100vw - 32px));padding:12px 16px;pointer-events:auto;text-align:center;animation:rise .6s cubic-bezier(.2,.9,.3,1.3) both}',
			'.bubble strong{display:block;font:600 16px/1.3 ' + look.heading + ';margin-bottom:2px}',
			'.bubble:after{content:"";position:absolute;left:50%;bottom:-7px;width:12px;height:12px;background:inherit;border:inherit;border-top:0;border-left:0;transform:translateX(-50%) rotate(45deg)}',
			'@keyframes rise{from{opacity:0;transform:translate(-50%,12px) scale(.96)}to{opacity:1;transform:translate(-50%,0)}}',
			// toasts
			'.toasts{position:absolute;left:18px;bottom:calc(18px + env(safe-area-inset-bottom));display:flex;flex-direction:column;gap:8px;align-items:flex-start}',
			'.toast{display:flex;align-items:center;gap:12px;padding:10px 12px 10px 16px;pointer-events:auto;animation:slide .35s cubic-bezier(.2,.9,.3,1.2) both;position:relative;overflow:hidden;max-width:min(420px,calc(100vw - 36px))}',
			'.toast.bad{border-color:#c0392b}',
			'.toast .undo{font-weight:600;color:' + look.accent + ';padding:4px 8px;border-radius:8px}',
			'.toast .undo:hover{background:' + look.accentSoft + '}',
			'.toast .bar{position:absolute;left:0;bottom:0;height:2px;background:' + look.accent + ';opacity:.5;animation:bar linear forwards}',
			'@keyframes bar{from{width:100%}to{width:0}}',
			'@keyframes slide{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}',
			'.toast.out{opacity:0;transform:translateY(6px);transition:.25s}',
			// floating things in the page
			'.float{position:absolute;pointer-events:auto}',
			'.handle{display:flex;gap:2px;padding:4px;border-radius:999px;animation:pop .2s cubic-bezier(.2,.9,.3,1.3) both}',
			'.handle button{width:32px;height:32px;border-radius:50%;display:grid;place-items:center}',
			'.handle button:hover{background:' + look.accentSoft + ';color:' + look.accent + '}',
			'.handle button.danger:hover{background:rgba(192,57,43,.12);color:#c0392b}',
			'.handle .text{width:auto;padding:0 12px;border-radius:999px;font-weight:600}',
			'.handle .text.primary{background:' + look.accent + ';color:' + look.onAccent + '}',
			'@keyframes pop{from{opacity:0;transform:scale(.85)}to{opacity:1;transform:none}}',
			'.badge{font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:3px 8px;border-radius:999px;background:' + look.fg + ';color:' + look.bg + ';pointer-events:none;transform:rotate(-3deg)}',
			'.photo-button{display:flex;align-items:center;gap:6px;padding:7px 12px;border-radius:999px;font-weight:600;background:rgba(20,16,12,.72);color:#fff;backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);animation:pop .2s both}',
			'.photo-button:hover{background:rgba(20,16,12,.85)}',
			'.drop{border:2px dashed ' + look.accent + ';background:' + look.accentSoft + ';border-radius:8px;display:grid;place-items:center;font-weight:700;color:' + look.accent + ';pointer-events:none}',
			'.tick{width:26px;height:26px;border-radius:50%;background:#3aa86b;color:#fff;display:grid;place-items:center;pointer-events:none;animation:tick 1.3s ease forwards}',
			'.tick svg{width:15px;height:15px;stroke-width:2.6}',
			'@keyframes tick{0%{opacity:0;transform:scale(.4)}15%{opacity:1;transform:scale(1.12)}25%{transform:scale(1)}75%{opacity:1}100%{opacity:0;transform:translateY(-6px)}}',
			'.ghost-label{display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:999px;background:' + look.accent + ';color:' + look.onAccent + ';font-weight:600;pointer-events:none;box-shadow:' + look.shadow + '}',
			'.format{display:flex;gap:2px;padding:4px;border-radius:10px;animation:pop .15s both}',
			'.format button{width:30px;height:30px;border-radius:7px;display:grid;place-items:center}',
			'.format button:hover,.format button.on{background:' + look.accentSoft + ';color:' + look.accent + '}',
			'.format input{border:0;background:transparent;color:inherit;font:inherit;width:220px;padding:0 8px;outline:none}',
			// popovers and dialogs
			'.popover{width:min(360px,calc(100vw - 24px));padding:16px;pointer-events:auto;animation:pop .18s both;max-height:min(70vh,560px);overflow:auto}',
			'.popover h3,.panel h3{margin:0 0 12px;font:600 17px/1.25 ' + look.heading + '}',
			'label.field{display:grid;gap:4px;margin-bottom:10px;font-size:12px;font-weight:600;color:' + look.muted + ';text-transform:uppercase;letter-spacing:.04em}',
			'input.in,textarea.in{font:15px/1.45 ' + look.font + ';color:' + look.fg + ';background:' + look.bg + ';border:1px solid ' + look.line + ';border-radius:8px;padding:8px 10px;width:100%;text-transform:none;letter-spacing:0;font-weight:400}',
			'textarea.in{min-height:84px;resize:vertical}',
			'label.field .sub{text-transform:none;letter-spacing:0;font-weight:400}',
			'.row{display:flex;gap:8px;justify-content:flex-end;align-items:center;margin-top:6px}',
			'.btn{height:34px;padding:0 14px;border-radius:999px;font-weight:600}',
			'.btn:hover{background:' + look.accentSoft + '}',
			'.btn.primary{background:' + look.accent + ';color:' + look.onAccent + '}',
			'.switch{display:flex;align-items:center;gap:8px;font-size:14px;color:' + look.fg + ';text-transform:none;letter-spacing:0;font-weight:400;margin-bottom:10px}',
			'.scrim{position:absolute;inset:0;background:rgba(10,8,6,.38);pointer-events:auto;animation:fade .2s both}',
			'@keyframes fade{from{opacity:0}}',
			'.dialog{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:min(560px,calc(100vw - 24px));max-height:calc(100vh - 24px);overflow:auto;padding:18px;pointer-events:auto;animation:popc .2s both}',
			'@keyframes popc{from{opacity:0;transform:translate(-50%,-50%) scale(.94)}to{opacity:1;transform:translate(-50%,-50%)}}',
			'.stage{position:relative;width:100%;background:#111;border-radius:10px;overflow:hidden;touch-action:none;cursor:grab}',
			'.stage:active{cursor:grabbing}',
			'.stage canvas{display:block;width:100%;height:100%}',
			'.stage .frame{position:absolute;inset:0;box-shadow:inset 0 0 0 2px rgba(255,255,255,.85);pointer-events:none;border-radius:10px}',
			'.hint{color:' + look.muted + ';font-size:13px;margin:8px 0 12px}',
			'input[type=range]{width:100%;accent-color:' + look.accent + '}',
			// the page panel
			'.panel{position:absolute;top:12px;right:12px;bottom:12px;width:min(380px,calc(100vw - 24px));padding:20px;overflow:auto;pointer-events:auto;animation:enter .35s cubic-bezier(.2,.9,.3,1.1) both}',
			'@keyframes enter{from{opacity:0;transform:translateX(24px)}to{opacity:1;transform:none}}',
			'.panel header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}',
			'.panel header h2{margin:0;font:600 22px/1.2 ' + look.heading + '}',
			'.panel section{padding:14px 0;border-top:1px solid ' + look.line + '}',
			'.panel h3{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:' + look.muted + ';font-family:' + look.font + '}',
			'.entry{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:7px 0}',
			'.entry .name{font-weight:600}',
			'.entry .sub{color:' + look.muted + ';font-size:13px}',
			'.link{color:' + look.accent + ';font-weight:600;padding:4px 8px;border-radius:8px}',
			'.link:hover{background:' + look.accentSoft + '}',
			'.muted{color:' + look.muted + '}',
			'.close{width:34px;height:34px;border-radius:50%;display:grid;place-items:center}',
			'.close:hover{background:' + look.accentSoft + '}',
			'@media (max-width:600px){.dock{left:10px;right:10px;transform:none;justify-content:space-between}.dock.hidden{transform:translateY(140%)}.bubble{bottom:calc(72px + env(safe-area-inset-bottom))}.toasts{left:10px;right:10px;bottom:calc(70px + env(safe-area-inset-bottom))}.panel{top:auto;left:8px;right:8px;width:auto;max-height:80vh;animation-name:up}}',
			'@keyframes up{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:none}}',
			reduceMotion ? '*,*:before,*:after{animation-duration:.01ms!important;animation-delay:0s!important;transition-duration:.01ms!important}' : ''
		].join('\n');
	}

	function buildChrome(look) {
		host = document.createElement('div');
		host.setAttribute('data-raster-host', '');
		document.body.appendChild(host);
		shadow = host.attachShadow({ mode: 'open' });
		shadow.appendChild(h('style', { text: chromeStyles(look) }));
		ui.layer = h('div', { class: 'layer' });
		shadow.appendChild(ui.layer);
		ui.floats = h('div');
		ui.layer.appendChild(ui.floats);
		ui.toasts = h('div', { class: 'toasts', role: 'status', 'aria-live': 'polite' });
		ui.layer.appendChild(ui.toasts);

		var initial = (C.user.name || '?').trim().charAt(0).toUpperCase();
		ui.who = h('span', { class: 'who', title: C.user.name, text: initial });
		ui.status = h('span', { class: 'status', hidden: true }, [h('span', { class: 'dot' }), h('span', { class: 'label', text: t('all_saved') })]);
		ui.editButton = h('button', { class: 'pill primary', title: t('keyboard'), onclick: function () { setEditing(!editing); } });
		ui.pageButton = h('button', { class: 'pill', onclick: togglePanel, html: icon('layers') + '<span>' + t('page') + '</span>' });
		ui.dock = h('div', { class: 'dock surface hidden', role: 'toolbar', 'aria-label': 'Raster' }, [ui.who, ui.status, ui.pageButton, ui.editButton]);
		ui.layer.appendChild(ui.dock);
		setTimeout(function () { ui.dock.classList.remove('hidden'); }, 60);
		updateDock();
	}

	// ##Status, ticks and toasts
	var busy = 0;
	function working(delta) {
		busy += delta;
		var dot = ui.status.querySelector('.dot'), label = ui.status.querySelector('.label');
		dot.classList.toggle('busy', busy > 0);
		label.textContent = busy > 0 ? t('saving') : t('all_saved');
	}
	function tick(el) {
		if (!el || !el.getBoundingClientRect) return;
		var r = el.getBoundingClientRect();
		var mark = h('div', { class: 'float tick', html: icon('check') });
		mark.style.left = Math.min(window.innerWidth - 34, r.right + 6) + 'px';
		mark.style.top = Math.max(6, r.top - 12) + 'px';
		ui.floats.appendChild(mark);
		setTimeout(function () { mark.remove(); }, 1400);
	}
	var undoStack = [];
	function toast(message, undo, bad) {
		var node = h('div', { class: 'toast surface' + (bad ? ' bad' : '') }, [h('span', { text: message })]);
		var life = bad ? 9000 : 7000;
		if (undo) {
			var entry = { run: undo, node: node };
			undoStack.push(entry);
			node.appendChild(h('button', { class: 'undo', text: t('undo'), onclick: function () { runUndo(entry); } }));
			var bar = h('span', { class: 'bar' });
			bar.style.animationDuration = life + 'ms';
			node.appendChild(bar);
		}
		ui.toasts.appendChild(node);
		while (ui.toasts.children.length > 3) ui.toasts.firstChild.remove();
		setTimeout(function () { dismiss(node); }, life);
	}
	function dismiss(node) {
		if (!node.parentNode) return;
		node.classList.add('out');
		setTimeout(function () { node.remove(); }, 260);
	}
	function runUndo(entry) {
		var i = undoStack.indexOf(entry);
		if (i < 0) return;
		undoStack.splice(i, 1);
		dismiss(entry.node);
		Promise.resolve(entry.run()).then(function () { toast(t('undone')); }, failed);
	}
	function failed(error) { toast(t('error', { error: error && error.message ? error.message : String(error) }), null, true); }

	// ##Saving fields
	function fieldValue(field) {
		if (field.kind === 'text') return field.el.innerText.replace(/ /g, ' ').replace(/\s*\n\s*/g, ' ').trim();
		return clean(field.el.innerHTML).trim();
	}
	function twins(field) { return fields.filter(function (f) { return f !== field && f.key === field.key; }); }
	function saveValue(field, value) {
		working(1);
		var request = field.item
			? call('editor_save_item', { collection: field.item.info.collection, id: field.item.info.id, fields: obj(field.info.field, value) }).then(function (item) { field.item.info.values = item; return item[field.info.field]; })
			: call('editor_save_field', { type: field.info.type, slug: field.info.slug, field: field.info.field, value: value }).then(function (r) { field.info.value = r.value; return r.value; });
		return request.then(function (saved) { working(-1); return saved; }, function (e) { working(-1); throw e; });
	}
	function obj(key, value) { var o = {}; o[key] = value; return o; }
	function show(field, value) {
		// an empty page field shows the template's text again
		var empty = value === '' && field.info.default != null && !field.item;
		var shown = empty ? field.info.default : value;
		[field].concat(twins(field)).forEach(function (f) {
			if (f.kind === 'image' || f.kind === 'attr') { if (shown) f.el.setAttribute(f.attr || 'src', shown); return; }
			if (f.kind === 'text') f.el.textContent = shown; else f.el.innerHTML = shown;
			if (empty) f.el.setAttribute('data-raster-default', ''); else f.el.removeAttribute('data-raster-default');
		});
	}
	function commit(field) {
		var value = fieldValue(field), before = field.before;
		field.before = null;
		if (before == null || value === before.value) return;
		saveValue(field, value).then(function () {
			show(field, value);
			tick(field.el);
			var label = field.item ? human(field.info.field) + ' · ' + itemName(field.item) : human(field.info.field);
			toast(t('changed', { field: label.toLowerCase() }), function () {
				return saveValue(field, before.value).then(function () { show(field, before.value); tick(field.el); });
			});
		}, function (e) {
			failed(e);
			field.el.focus();
		});
	}

	// ##Cleaning pasted and formatted HTML
	var allowed = { P: 1, BR: 1, STRONG: 1, B: 1, EM: 1, I: 1, A: 1, UL: 1, OL: 1, LI: 1, H2: 1, H3: 1, H4: 1, BLOCKQUOTE: 1 };
	function clean(html) {
		var box = document.createElement('template');
		box.innerHTML = html;
		(function walk(node) {
			Array.prototype.slice.call(node.childNodes).forEach(function (child) {
				if (child.nodeType === 8) { child.remove(); return; }
				if (child.nodeType !== 1) return;
				walk(child);
				if (!allowed[child.tagName]) {
					if (child.tagName === 'DIV') { var p = document.createElement('p'); while (child.firstChild) p.appendChild(child.firstChild); child.replaceWith(p); return; }
					while (child.firstChild) child.parentNode.insertBefore(child.firstChild, child);
					child.remove();
					return;
				}
				Array.prototype.slice.call(child.attributes).forEach(function (a) {
					if (!(child.tagName === 'A' && a.name === 'href')) child.removeAttribute(a.name);
				});
				if (child.tagName === 'A' && /^\s*(javascript|data):/i.test(child.getAttribute('href') || '')) child.removeAttribute('href');
			});
		})(box.content);
		var out = document.createElement('div');
		out.appendChild(box.content.cloneNode(true));
		return out.innerHTML.replace(/<p><\/p>/g, '');
	}

	// ##Edit mode
	var editing = false;
	function setEditing(on, quiet) {
		editing = on;
		document.documentElement.classList.toggle('raster-editing', on);
		fields.forEach(function (f) {
			if (f.kind === 'text') f.el.setAttribute('contenteditable', plaintextOnly ? 'plaintext-only' : 'true');
			if (f.kind === 'rich') f.el.setAttribute('contenteditable', 'true');
			if (!on) f.el.removeAttribute('contenteditable');
			if (on && (f.kind === 'text' || f.kind === 'rich')) { f.el.setAttribute('spellcheck', 'true'); if (!f.el.hasAttribute('aria-label')) f.el.setAttribute('aria-label', human(f.info.field)); }
		});
		if (on) { glow(); addGhosts(); } else { removeGhosts(); clearFloats(); if (document.activeElement && document.activeElement.isContentEditable) document.activeElement.blur(); }
		store('raster-editing', on ? '1' : null);
		updateDock();
		if (!quiet) toast(on ? t('edit_mode_on') : t('edit_mode_off'));
		closeBubble();
	}
	var plaintextOnly = (function () { var d = document.createElement('div'); d.setAttribute('contenteditable', 'plaintext-only'); return d.contentEditable === 'plaintext-only'; })();
	function updateDock() {
		ui.editButton.innerHTML = (editing ? icon('check') : icon('pencil')) + '<span>' + (editing ? t('done') : t('edit')) + '</span>';
		ui.status.hidden = !editing;
	}
	function glow() {
		if (reduceMotion) return;
		var root = document.documentElement;
		root.classList.remove('raster-glow');
		void root.offsetWidth;
		root.classList.add('raster-glow');
		setTimeout(function () { root.classList.remove('raster-glow'); }, 1100 + Math.min(fields.length, 30) * 45);
	}

	// focus, typing, leaving
	document.addEventListener('focusin', function (e) {
		var field = fieldFor(e.target);
		if (!field || !editing || field.kind === 'image') return;
		if (field.before == null) field.before = { value: fieldValue(field), html: field.el.innerHTML };
		if (field.el.hasAttribute('data-raster-default')) field.el.removeAttribute('data-raster-default');
		if (field.kind === 'rich') showFormatBar(field); else hideFormatBar();
	});
	document.addEventListener('focusout', function (e) {
		var field = fieldFor(e.target);
		if (!field || !editing) return;
		setTimeout(function () {
			if (formatBarHasFocus()) return;
			if (field.kind === 'rich') hideFormatBar();
			commit(field);
		}, 0);
	});
	document.addEventListener('keydown', function (e) {
		var field = fieldFor(e.target);
		if (field && editing) {
			if (e.key === 'Escape') {
				e.preventDefault();
				if (field.before) { field.el.innerHTML = field.before.html; field.before = null; }
				field.el.blur();
				return;
			}
			if (e.key === 'Enter' && (e.metaKey || e.ctrlKey || field.kind === 'text')) { e.preventDefault(); field.el.blur(); return; }
			return;
		}
		if (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable || (host && e.composedPath && e.composedPath().some(function (n) { return n.tagName === 'INPUT' || n.tagName === 'TEXTAREA'; }))) return;
		if ((e.metaKey || e.ctrlKey) && !e.shiftKey && e.key.toLowerCase() === 'z') {
			if (!undoStack.length) { if (editing) { e.preventDefault(); toast(t('nothing_to_undo')); } return; }
			e.preventDefault();
			runUndo(undoStack[undoStack.length - 1]);
			return;
		}
		if (e.metaKey || e.ctrlKey || e.altKey) return;
		if (e.key === 'e' || e.key === 'E') { e.preventDefault(); setEditing(!editing); }
		else if (e.key === 'Escape') { if (closeFloating()) return; if (panelOpen()) { togglePanel(); return; } if (editing) setEditing(false); }
	});
	document.addEventListener('paste', function (e) {
		var field = fieldFor(e.target);
		if (!field || !editing) return;
		e.preventDefault();
		var data = e.clipboardData;
		if (field.kind === 'rich' && data.getData('text/html')) document.execCommand('insertHTML', false, clean(data.getData('text/html')));
		else document.execCommand('insertText', false, data.getData('text/plain'));
	});
	// in edit mode a click on an editable link edits it instead of leaving
	document.addEventListener('click', function (e) {
		if (!editing) return;
		var field = fieldFor(e.target);
		if (field && (field.kind === 'text' || field.kind === 'rich')) {
			var link = e.target.closest('a');
			if (link && (link.contains(field.el) || field.el.contains(link))) e.preventDefault();
		}
		if (field && field.kind === 'image') { e.preventDefault(); pickPhoto(field); }
		var itemEl = e.target.closest && e.target.closest('[data-raster-item]');
		if (touch && itemEl && !field) activateItem(items[itemEl.getAttribute('data-raster-item')]);
	}, true);
	function fieldFor(node) {
		if (!node || !node.closest) node = node && node.parentElement;
		var el = node && node.closest && node.closest('[data-raster-field]');
		if (!el) return null;
		var id = el.getAttribute('data-raster-field');
		for (var i = 0; i < fields.length; i++) if (fields[i].id === id) return fields[i];
		return null;
	}

	// ##Formatting rich text
	var formatBar = null;
	function showFormatBar(field) {
		hideFormatBar();
		var commands = [['bold', 'bold'], ['italic', 'italic'], ['link', 'link'], ['list', 'insertUnorderedList'], ['clear', 'removeFormat']];
		formatBar = h('div', { class: 'float format surface', role: 'toolbar' });
		commands.forEach(function (c) {
			formatBar.appendChild(h('button', { title: t(c[0]), 'aria-label': t(c[0]), html: icon(c[0]), onmousedown: function (e) {
				e.preventDefault();
				if (c[0] === 'link') return askLink(field);
				document.execCommand(c[1], false, null);
			} }));
		});
		ui.floats.appendChild(formatBar);
		formatBar.field = field;
		placeFormatBar();
	}
	function askLink(field) {
		var selection = window.getSelection();
		var range = selection.rangeCount ? selection.getRangeAt(0).cloneRange() : null;
		formatBar.innerHTML = '';
		var input = h('input', { type: 'url', placeholder: t('link_prompt'), value: 'https://' });
		var done = function (apply) {
			var url = input.value.trim();
			field.el.focus();
			if (range) { selection.removeAllRanges(); selection.addRange(range); }
			if (apply && url && url !== 'https://') document.execCommand('createLink', false, url);
			showFormatBar(field);
		};
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); done(true); } if (e.key === 'Escape') { e.preventDefault(); done(false); } });
		formatBar.appendChild(input);
		formatBar.appendChild(h('button', { html: icon('check'), onclick: function () { done(true); } }));
		input.focus();
		input.select();
	}
	function formatBarHasFocus() { return formatBar && shadow.activeElement && formatBar.contains(shadow.activeElement); }
	function hideFormatBar() { if (formatBar) { formatBar.remove(); formatBar = null; } }
	function placeFormatBar() {
		if (!formatBar) return;
		var r = formatBar.field.el.getBoundingClientRect();
		formatBar.style.left = Math.max(8, Math.min(window.innerWidth - 240, r.left)) + 'px';
		formatBar.style.top = (r.top > 60 ? r.top - 48 : r.bottom + 8) + 'px';
	}

	// ##Items: the handle, drafts, schedules, deleting
	var activeItem = null, handle = null, badges = [];
	function clearFloats() {
		if (handle) { handle.remove(); handle = null; }
		if (activeItem) activeItem.el.classList.remove('raster-active');
		activeItem = null;
		closeFloating();
	}
	function activateItem(item) {
		if (!item || !editing || item === activeItem) return;
		if (handle) handle.remove();
		if (activeItem) activeItem.el.classList.remove('raster-active');
		activeItem = item;
		item.el.classList.add('raster-active');
		var state = itemState(item.info);
		handle = h('div', { class: 'float handle surface', role: 'toolbar', 'aria-label': itemName(item) }, [
			button('pencil', t('details'), function () { openDetails(item); }),
			button('copy', t('duplicate'), function () { duplicate(item); }),
			button(state === 'draft' ? 'eye' : 'eyeoff', state === 'draft' ? t('show') : t('hide'), function () { toggleHidden(item); }),
			button('clock', t('schedule'), function () { openSchedule(item); }),
			button('trash', t('delete'), function () { remove(item); }, 'danger')
		]);
		handle.addEventListener('mouseleave', function (e) { if (!item.el.contains(e.relatedTarget)) scheduleDeactivate(); });
		handle.addEventListener('mouseenter', cancelDeactivate);
		ui.floats.appendChild(handle);
		place();
	}
	function button(name, label, action, extra) {
		return h('button', { class: extra || '', title: label, 'aria-label': label, html: icon(name), onclick: function (e) { e.stopPropagation(); action(); } });
	}
	var deactivateTimer = null;
	function scheduleDeactivate() { cancelDeactivate(); deactivateTimer = setTimeout(function () { if (!floating) clearFloats(); }, 400); }
	function cancelDeactivate() { clearTimeout(deactivateTimer); }
	document.addEventListener('mouseover', function (e) {
		if (!editing || touch) return;
		var el = e.target.closest && e.target.closest('[data-raster-item]');
		if (el) { cancelDeactivate(); activateItem(items[el.getAttribute('data-raster-item')]); }
		var img = e.target.closest && e.target.closest('img[data-raster-field]');
		if (img) showPhotoButton(fieldFor(img));
	});
	document.addEventListener('mouseout', function (e) {
		if (!editing || touch || !activeItem) return;
		var to = e.relatedTarget;
		if (to && (activeItem.el.contains(to) || to === host)) return;
		scheduleDeactivate();
	});

	function place() {
		if (handle && activeItem) {
			var r = activeItem.el.getBoundingClientRect();
			handle.style.left = Math.max(8, Math.min(window.innerWidth - handle.offsetWidth - 8, r.right - handle.offsetWidth)) + 'px';
			handle.style.top = Math.max(8, r.top - handle.offsetHeight - 10) + 'px';
		}
		badges.forEach(function (b) {
			var rr = b.item.el.getBoundingClientRect();
			b.node.style.left = (rr.left + 10) + 'px';
			b.node.style.top = (rr.top + 10) + 'px';
			b.node.style.display = rr.bottom < 0 || rr.top > window.innerHeight ? 'none' : '';
		});
		if (photoButton && photoButton.field) {
			var pr = photoButton.field.el.getBoundingClientRect();
			photoButton.style.left = (pr.left + pr.width / 2 - photoButton.offsetWidth / 2) + 'px';
			photoButton.style.top = (pr.top + pr.height / 2 - photoButton.offsetHeight / 2) + 'px';
		}
		ghosts.forEach(function (g) {
			if (!g.label) return;
			var gr = g.el.getBoundingClientRect();
			g.label.style.left = (gr.left + gr.width / 2 - g.label.offsetWidth / 2) + 'px';
			g.label.style.top = (gr.top + gr.height / 2 - g.label.offsetHeight / 2) + 'px';
		});
		placeFormatBar();
		if (floating && floating.anchor) anchorTo(floating, floating.anchor);
	}
	var placing = false;
	function schedulePlace() { if (placing) return; placing = true; requestAnimationFrame(function () { placing = false; place(); }); }
	window.addEventListener('scroll', schedulePlace, { passive: true });
	window.addEventListener('resize', schedulePlace);

	function refreshBadges() {
		badges.forEach(function (b) { b.node.remove(); });
		badges = [];
		Object.keys(items).forEach(function (id) {
			var item = items[id], state = itemState(item.info);
			item.el.setAttribute('data-raster-state', state);
			if (state === 'live') return;
			var node = h('div', { class: 'float badge', text: state === 'draft' ? t('draft') : t('goes_live', { date: day(item.info.published_at) }) });
			ui.floats.appendChild(node);
			badges.push({ item: item, node: node });
		});
		place();
	}

	function saveItem(item, values) {
		working(1);
		return call('editor_save_item', { collection: item.info.collection, id: item.info.id, fields: values }).then(function (saved) {
			working(-1);
			item.info.values = saved;
			item.info.enabled = saved.enabled;
			item.info.published_at = saved.published_at;
			refreshBadges();
			return saved;
		}, function (e) { working(-1); throw e; });
	}
	function toggleHidden(item) {
		var wasDraft = itemState(item.info) === 'draft';
		saveItem(item, { enabled: wasDraft ? '1' : '0' }).then(function () {
			clearFloats();
			toast(t(wasDraft ? 'shown_item' : 'hidden_item', { name: itemName(item) }), function () { return saveItem(item, { enabled: wasDraft ? '0' : '1' }); });
		}, failed);
	}
	function detachItem(item) {
		var nodes = [item.start].concat(between(item.start, item.end), [item.end]);
		var anchor = item.end.nextSibling, parent = item.end.parentNode;
		nodes.forEach(function (n) { n.remove(); });
		return function () { nodes.forEach(function (n) { parent.insertBefore(n, anchor); }); };
	}
	function remove(item) {
		clearFloats();
		item.el.classList.add('raster-leaving');
		working(1);
		call('editor_delete_item', { collection: item.info.collection, id: item.info.id }).then(function (result) {
			working(-1);
			var putBack;
			setTimeout(function () { putBack = detachItem(item); delete items[item.id]; refreshBadges(); }, reduceMotion ? 0 : 340);
			toast(t('deleted', { name: itemName(item) }), function () {
				var values = Object.assign({}, result.item);
				delete values.id; delete values.updated_at;
				return call('editor_save_item', { collection: item.info.collection, id: 0, fields: values }).then(function (saved) {
					item.info.id = saved.id;
					item.info.values = saved;
					if (putBack) putBack();
					item.el.classList.remove('raster-leaving');
					item.el.classList.add('raster-arriving');
					items[item.id] = item;
					refreshBadges();
				});
			});
		}, function (e) { working(-1); item.el.classList.remove('raster-leaving'); failed(e); });
	}
	function duplicate(item) {
		var values = Object.assign({}, item.info.values);
		['id', 'updated_at', 'slug'].forEach(function (k) { delete values[k]; });
		working(1);
		call('editor_save_item', { collection: item.info.collection, id: 0, fields: values }).then(function (saved) {
			working(-1);
			var copy = cloneItem(item, saved);
			clearFloats();
			toast(t('duplicated', { name: itemName(item) }), function () {
				return call('editor_delete_item', { collection: copy.info.collection, id: copy.info.id }).then(function () { detachItem(copy); delete items[copy.id]; refreshBadges(); });
			});
		}, function (e) { working(-1); failed(e); });
	}
	// a copy of an item's markup for a new item, placed after it
	var nextId = 100000;
	function cloneItem(item, saved) {
		var id = String(nextId++);
		var info = { kind: 'item', collection: item.info.collection, id: saved.id, enabled: saved.enabled, published_at: saved.published_at, values: saved };
		marks[id] = info;
		var nodes = between(item.start, item.end).map(function (n) { return n.cloneNode(true); });
		var start = document.createComment('raster:s ' + id), end = document.createComment('raster:e ' + id);
		var anchor = item.end.nextSibling, parent = item.end.parentNode;
		parent.insertBefore(start, anchor);
		nodes.forEach(function (n) { parent.insertBefore(n, anchor); });
		parent.insertBefore(end, anchor);
		return adopt(id, info, start, end);
	}
	// registers an item added in the page, with its fields
	function adopt(id, info, start, end) {
		var el = between(start, end).filter(function (n) { return n.nodeType === 1; })[0];
		var item = { id: id, info: info, start: start, end: end, el: el, fields: [] };
		items[id] = item;
		el.setAttribute('data-raster-item', id);
		el.classList.remove('raster-active', 'raster-ghost', 'raster-new');
		el.classList.add('raster-arriving');
		var parts = el.querySelectorAll('[data-raster-field]');
		if (el.hasAttribute('data-raster-field')) parts = [el].concat(Array.prototype.slice.call(parts));
		Array.prototype.forEach.call(parts, function (node) {
			var old = null, oldId = node.getAttribute('data-raster-field');
			for (var i = 0; i < fields.length; i++) if (fields[i].id === oldId) old = fields[i];
			var name = old ? old.info.field : node.getAttribute('data-raster-name');
			if (!name) return;
			var fid = String(nextId++);
			var finfo = { kind: old && old.kind === 'image' ? 'item_attr' : 'item_field', item: id, field: name, attr: old ? old.attr : node.getAttribute('data-raster-attr') };
			marks[fid] = finfo;
			var kind = old ? old.kind : (node.tagName === 'IMG' ? 'image' : (/<[a-z]/i.test(node.innerHTML) ? 'rich' : 'text'));
			var field = { id: fid, info: finfo, el: node, kind: kind, attr: finfo.attr, item: item, key: 'item:' + id + ':' + name };
			node.setAttribute('data-raster-field', fid);
			if (kind === 'image' || kind === 'attr') node.removeAttribute('data-raster-edit'); else node.setAttribute('data-raster-edit', kind);
			if (editing && kind !== 'image') node.setAttribute('contenteditable', kind === 'text' && plaintextOnly ? 'plaintext-only' : 'true');
			item.fields.push(field);
			fields.push(field);
		});
		var fresh = values(item);
		Object.keys(fresh).forEach(function (name) {
			item.fields.forEach(function (f) { if (f.info.field === name) { if (f.kind === 'image') f.el.setAttribute('src', fresh[name] || f.el.getAttribute('src')); else if (f.kind === 'text') f.el.textContent = fresh[name]; else f.el.innerHTML = fresh[name]; } });
		});
		lists.forEach(function (list) { if (list.info.collection === info.collection) list.items.push(item); });
		refreshBadges();
		return item;
	}
	function values(item) { var v = Object.assign({}, item.info.values || {}); ['id', 'updated_at', 'slug', 'enabled', 'published_at'].forEach(function (k) { delete v[k]; }); return v; }

	// ##Popovers
	var floating = null;
	function closeFloating() {
		if (!floating) return false;
		floating.remove();
		if (floating.scrim) floating.scrim.remove();
		floating = null;
		return true;
	}
	function anchorTo(node, el) {
		var r = el.getBoundingClientRect();
		var width = node.offsetWidth || 360, height = node.offsetHeight || 300;
		var left = Math.max(12, Math.min(window.innerWidth - width - 12, r.left));
		var top = r.bottom + 10;
		if (top + height > window.innerHeight - 12) top = Math.max(12, r.top - height - 10);
		node.style.left = left + 'px';
		node.style.top = top + 'px';
	}
	function popover(title, body, anchor) {
		closeFloating();
		var node = h('div', { class: 'float popover surface', role: 'dialog', 'aria-label': title }, [h('h3', { text: title })].concat(body));
		ui.floats.appendChild(node);
		node.anchor = anchor;
		floating = node;
		anchorTo(node, anchor);
		var first = node.querySelector('input, textarea, button');
		if (first) first.focus();
		return node;
	}
	document.addEventListener('mousedown', function (e) {
		if (floating && !e.composedPath().some(function (n) { return n === floating; }) && e.target !== host) closeFloating();
	});
	function openDetails(item) {
		var inputs = {}, body = [];
		var v = values(item);
		Object.keys(v).forEach(function (name) {
			var value = v[name] == null ? '' : String(v[name]);
			var photo = item.fields.filter(function (f) { return f.info.field === name && f.kind === 'image'; })[0];
			if (photo || /\.(jpe?g|png|gif|webp|avif|svg)(\?.*)?$/i.test(value)) {
				var thumb = h('img', { src: photo ? photo.el.currentSrc || photo.el.src : value, alt: '' });
				var pick = h('button', { class: 'btn', type: 'button', html: icon('camera') + ' ' + t('replace_photo'), onclick: function () {
					pickPhoto({ el: thumb, kind: 'image', pending: true, ratioEl: photo ? photo.el : null, info: { field: name } });
				} });
				pick.style.display = 'inline-flex'; pick.style.alignItems = 'center'; pick.style.gap = '6px';
				inputs[name] = { get value() { return thumb.getAttribute('data-raster-value') || value; } };
				body.push(h('label', { class: 'field' }, [human(name), h('div', { class: 'thumb' }, [thumb, pick])]));
				return;
			}
			var long = value.length > 70 || /<[a-z]/i.test(value);
			var input = long ? h('textarea', { class: 'in' }) : h('input', { class: 'in', type: 'text' });
			input.value = value;
			inputs[name] = input;
			body.push(h('label', { class: 'field' }, [human(name), input]));
		});
		var slug = h('input', { class: 'in', type: 'text', value: item.info.values.slug || '' });
		body.push(h('label', { class: 'field' }, [t('address'), slug]));
		body.push(h('div', { class: 'row' }, [
			h('button', { class: 'btn', text: t('cancel'), onclick: closeFloating }),
			h('button', { class: 'btn primary', text: t('save'), onclick: function () {
				var changed = {}, before = {};
				Object.keys(inputs).forEach(function (name) { if (inputs[name].value !== String(v[name] == null ? '' : v[name])) { changed[name] = inputs[name].value; before[name] = v[name]; } });
				if (slug.value !== (item.info.values.slug || '')) { changed.slug = slug.value; before.slug = item.info.values.slug; }
				if (!Object.keys(changed).length) return closeFloating();
				saveItem(item, changed).then(function (saved) {
					closeFloating();
					applyValues(item, saved);
					tick(item.el);
					toast(t('changed', { field: itemName(item).toLowerCase() }), function () { return saveItem(item, before).then(function (s) { applyValues(item, s); }); });
				}, failed);
			} })
		]));
		popover(itemName(item), body, item.el);
	}
	function applyValues(item, saved) {
		item.fields.forEach(function (f) {
			if (!(f.info.field in saved)) return;
			var value = saved[f.info.field] == null ? '' : String(saved[f.info.field]);
			if (f.kind === 'image' || f.kind === 'attr') { if (value) f.el.setAttribute(f.attr || 'src', value); }
			else if (f.kind === 'text') f.el.textContent = value;
			else f.el.innerHTML = value;
		});
	}
	function localInput(value) {
		var d = value ? new Date(String(value).replace(' ', 'T')) : new Date(Date.now() + 86400000);
		if (isNaN(d)) d = new Date(Date.now() + 86400000);
		var pad = function (n) { return String(n).padStart(2, '0'); };
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
	}
	function openSchedule(item) {
		var input = h('input', { class: 'in', type: 'datetime-local', value: localInput(item.info.published_at) });
		var before = item.info.published_at || '';
		var save = function (value) {
			saveItem(item, { published_at: value, enabled: '1' }).then(function () {
				closeFloating();
				clearFloats();
				toast(value ? t('scheduled', { name: itemName(item), date: day(value) }) : t('unscheduled', { name: itemName(item) }), function () { return saveItem(item, { published_at: before }); });
			}, failed);
		};
		popover(t('schedule') + ' · ' + itemName(item), [
			h('label', { class: 'field' }, [t('publish_at'), input]),
			h('div', { class: 'row' }, [
				h('button', { class: 'btn', text: t('publish_now'), onclick: function () { save(''); } }),
				h('button', { class: 'btn primary', text: t('save'), onclick: function () { save(input.value ? input.value.replace('T', ' ') + ':00' : ''); } })
			])
		], item.el);
	}

	// ##New items: the ghost at the end of each list
	var ghosts = [];
	function addGhosts() {
		removeGhosts();
		if (/_item\//.test(location.pathname)) return;
		lists.forEach(function (list) {
			if (!list.template) return;
			var box = document.createElement('div');
			box.appendChild(list.template.content.cloneNode(true));
			var el = Array.prototype.filter.call(box.childNodes, function (n) { return n.nodeType === 1; })[0];
			if (!el) return;
			// fields in the mock-up
			var walker = document.createTreeWalker(el.parentNode, NodeFilter.SHOW_COMMENT), node, marked = [];
			while ((node = walker.nextNode())) marked.push(node);
			marked.forEach(function (c) {
				var m = /^raster:m (\S+)$/.exec(c.data), ma = /^raster:ma (\S+) (\S+)$/.exec(c.data);
				if (m) {
					var end = c.nextSibling;
					while (end && !(end.nodeType === 8 && end.data === 'raster:/m')) end = end.nextSibling;
					var r = region(c, end);
					if (r) { r.setAttribute('data-raster-name', m[1]); r.setAttribute('data-raster-field', 'new'); r.setAttribute('data-raster-edit', /<[a-z]/i.test(r.innerHTML) ? 'rich' : 'text'); }
				} else if (ma) {
					var target = nextElement(c);
					if (target) { target.setAttribute('data-raster-name', ma[1]); target.setAttribute('data-raster-attr', ma[2]); target.setAttribute('data-raster-field', 'new'); }
				}
			});
			Array.prototype.slice.call(el.querySelectorAll('a[href]')).forEach(function (a) { a.removeAttribute('href'); });
			el.classList.add('raster-ghost');
			el.setAttribute('aria-label', t('add_to', { list: human(list.info.collection).toLowerCase() }));
			var last = list.items.length ? list.items[list.items.length - 1].end : list.template;
			last.parentNode.insertBefore(el, last.nextSibling);
			var label = h('div', { class: 'float ghost-label', html: icon('plus') + '<span>' + t('add_to', { list: human(list.info.collection).toLowerCase() }) + '</span>' });
			ui.floats.appendChild(label);
			var ghost = { el: el, label: label, list: list };
			el.addEventListener('click', function (e) { if (!el.classList.contains('raster-new')) { e.preventDefault(); startNew(ghost); } });
			ghosts.push(ghost);
		});
		place();
	}
	function removeGhosts() {
		ghosts.forEach(function (g) { g.el.remove(); if (g.label) g.label.remove(); if (g.bar) g.bar.remove(); });
		ghosts = [];
	}
	function startNew(ghost) {
		var el = ghost.el;
		el.classList.add('raster-new');
		if (ghost.label) { ghost.label.remove(); ghost.label = null; }
		var parts = Array.prototype.slice.call(el.querySelectorAll('[data-raster-name]'));
		if (el.hasAttribute('data-raster-name')) parts.unshift(el);
		parts.forEach(function (node) {
			if (node.tagName === 'IMG') {
				node.addEventListener('click', function () { pickPhoto({ el: node, kind: 'image', attr: node.getAttribute('data-raster-attr'), pending: true, info: { field: node.getAttribute('data-raster-name') } }); });
				return;
			}
			node.setAttribute('contenteditable', node.getAttribute('data-raster-edit') === 'text' && plaintextOnly ? 'plaintext-only' : 'true');
			node.setAttribute('data-raster-default', '');
			node.addEventListener('focus', function () {
				if (node.hasAttribute('data-raster-default')) {
					node.removeAttribute('data-raster-default');
					var range = document.createRange();
					range.selectNodeContents(node);
					var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
				}
			});
		});
		var first = parts.filter(function (n) { return n.tagName !== 'IMG'; })[0];
		if (first) first.focus();
		var bar = h('div', { class: 'float handle surface' }, [
			h('button', { class: 'text', text: t('cancel'), onclick: function () { removeGhosts(); addGhosts(); } }),
			h('button', { class: 'text primary', html: t('add'), onclick: function () { createFrom(ghost, parts); } })
		]);
		ui.floats.appendChild(bar);
		ghost.bar = bar;
		var follow = function () { if (!bar.parentNode) return; var r = el.getBoundingClientRect(); bar.style.left = Math.max(8, r.right - bar.offsetWidth) + 'px'; bar.style.top = Math.max(8, r.top - bar.offsetHeight - 10) + 'px'; requestAnimationFrame(follow); };
		follow();
	}
	function createFrom(ghost, parts) {
		var list = ghost.list, fieldsOut = Object.assign({}, list.info.filters && !Array.isArray(list.info.filters) ? list.info.filters : {});
		parts.forEach(function (node) {
			var name = node.getAttribute('data-raster-name');
			if (node.tagName === 'IMG') { fieldsOut[name] = node.getAttribute('data-raster-value') || (list.info.fields || {})[name] || ''; return; }
			fieldsOut[name] = node.getAttribute('data-raster-edit') === 'text' ? node.innerText.replace(/\s+/g, ' ').trim() : clean(node.innerHTML).trim();
		});
		working(1);
		call('editor_save_item', { collection: list.info.collection, id: 0, fields: fieldsOut }).then(function (saved) {
			working(-1);
			var el = ghost.el;
			if (ghost.bar) ghost.bar.remove();
			ghosts.splice(ghosts.indexOf(ghost), 1);
			var id = String(nextId++);
			var info = { kind: 'item', collection: list.info.collection, id: saved.id, enabled: saved.enabled, published_at: saved.published_at, values: saved };
			marks[id] = info;
			var start = document.createComment('raster:s ' + id), end = document.createComment('raster:e ' + id);
			el.parentNode.insertBefore(start, el);
			el.parentNode.insertBefore(end, el.nextSibling);
			el.removeAttribute('aria-label');
			parts.forEach(function (n) { n.removeAttribute('contenteditable'); n.removeAttribute('data-raster-default'); });
			var item = adopt(id, info, start, end);
			tick(item.el);
			toast(t('added', { list: human(list.info.collection).toLowerCase() }), function () {
				return call('editor_delete_item', { collection: info.collection, id: info.id }).then(function () { detachItem(item); delete items[id]; refreshBadges(); });
			});
			addGhosts();
		}, function (e) { working(-1); failed(e); });
	}

	// ##Photos: pick, frame, upload
	var photoButton = null;
	function showPhotoButton(field) {
		if (!field || !editing) return;
		if (photoButton && photoButton.field === field) return;
		if (photoButton) photoButton.remove();
		photoButton = h('button', { class: 'float photo-button', html: icon('camera') + '<span>' + t('replace_photo') + '</span>', onclick: function () { pickPhoto(field); } });
		photoButton.field = field;
		ui.floats.appendChild(photoButton);
		place();
		var leave = function (e) {
			if (photoButton && photoButton.field === field && !field.el.contains(e.relatedTarget) && e.relatedTarget !== host) { photoButton.remove(); photoButton = null; }
		};
		field.el.addEventListener('mouseleave', leave, { once: true });
	}
	function pickPhoto(field) {
		var input = document.createElement('input');
		input.type = 'file';
		input.accept = 'image/*';
		input.addEventListener('change', function () { if (input.files[0]) framePhoto(field, input.files[0]); });
		input.click();
	}
	// dropping a picture on a photo replaces it
	document.addEventListener('dragover', function (e) {
		if (!editing) return;
		var img = e.target.closest && e.target.closest('img[data-raster-field]');
		if (img && e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') >= 0) { e.preventDefault(); showDrop(img); }
	});
	document.addEventListener('dragleave', function (e) { if (dropZone && !(e.relatedTarget && e.relatedTarget.closest && e.relatedTarget.closest('img[data-raster-field]'))) hideDrop(); });
	document.addEventListener('drop', function (e) {
		if (!editing) return;
		var img = e.target.closest && e.target.closest('img[data-raster-field]');
		hideDrop();
		if (!img || !e.dataTransfer.files[0]) return;
		e.preventDefault();
		framePhoto(fieldFor(img), e.dataTransfer.files[0]);
	});
	var dropZone = null;
	function showDrop(img) {
		if (dropZone) return;
		var r = img.getBoundingClientRect();
		dropZone = h('div', { class: 'float drop', text: t('drop_photo') });
		dropZone.style.cssText = 'left:' + r.left + 'px;top:' + r.top + 'px;width:' + r.width + 'px;height:' + r.height + 'px';
		ui.floats.appendChild(dropZone);
	}
	function hideDrop() { if (dropZone) { dropZone.remove(); dropZone = null; } }

	function framePhoto(field, file) {
		if (!file || !/^image\//.test(file.type)) return failed(new Error('not a picture'));
		var url = URL.createObjectURL(file);
		var image = new Image();
		image.onload = function () { cropDialog(field, file, image, url); };
		image.onerror = function () { failed(new Error('not a picture')); };
		image.src = url;
	}
	function cropDialog(field, file, image, url) {
		closeFloating();
		var box = (field.ratioEl || field.el).getBoundingClientRect();
		var ratio = box.width > 20 && box.height > 20 ? box.width / box.height : image.width / image.height;
		var outW = Math.round(Math.min(2000, Math.max(box.width * Math.min(window.devicePixelRatio || 1, 2), 800)));
		var outH = Math.round(outW / ratio);
		var canvas = h('canvas');
		var stage = h('div', { class: 'stage' }, [canvas, h('div', { class: 'frame' })]);
		var zoom = h('input', { type: 'range', min: '1', max: '4', step: '0.01', value: '1', 'aria-label': t('zoom') });
		var state = { scale: 1, x: 0, y: 0 };
		var base = Math.max(outW / image.width, outH / image.height);
		function clamp() {
			var w = image.width * base * state.scale, hgt = image.height * base * state.scale;
			state.x = Math.min(0, Math.max(outW - w, state.x));
			state.y = Math.min(0, Math.max(outH - hgt, state.y));
		}
		function draw(target, w, hgt) {
			var ctx = target.getContext('2d');
			var k = w / outW;
			ctx.fillStyle = '#fff';
			ctx.fillRect(0, 0, w, hgt);
			ctx.imageSmoothingQuality = 'high';
			ctx.drawImage(image, state.x * k, state.y * k, image.width * base * state.scale * k, image.height * base * state.scale * k);
		}
		function redraw() {
			// the frame keeps the photo's shape and fits the screen
			stage.style.width = Math.min(dialog.clientWidth - 36 || 520, Math.max(160, (window.innerHeight - 260) * ratio)) + 'px';
			stage.style.margin = '0 auto';
			var cw = stage.clientWidth || 520;
			canvas.width = Math.round(cw * (window.devicePixelRatio || 1));
			canvas.height = Math.round(canvas.width / ratio);
			stage.style.height = Math.round(cw / ratio) + 'px';
			clamp();
			draw(canvas, canvas.width, canvas.height);
		}
		state.x = (outW - image.width * base) / 2;
		state.y = (outH - image.height * base) / 2;
		var drag = null;
		stage.addEventListener('pointerdown', function (e) { drag = { x: e.clientX, y: e.clientY, sx: state.x, sy: state.y }; stage.setPointerCapture(e.pointerId); });
		stage.addEventListener('pointermove', function (e) {
			if (!drag) return;
			var k = outW / stage.clientWidth;
			state.x = drag.sx + (e.clientX - drag.x) * k;
			state.y = drag.sy + (e.clientY - drag.y) * k;
			redraw();
		});
		stage.addEventListener('pointerup', function () { drag = null; });
		stage.addEventListener('wheel', function (e) { e.preventDefault(); setZoom(state.scale * (e.deltaY < 0 ? 1.06 : 0.94)); }, { passive: false });
		function setZoom(value) {
			value = Math.min(4, Math.max(1, value));
			var cx = outW / 2, cy = outH / 2;
			state.x = cx - (cx - state.x) * value / state.scale;
			state.y = cy - (cy - state.y) * value / state.scale;
			state.scale = value;
			zoom.value = value;
			redraw();
		}
		zoom.addEventListener('input', function () { setZoom(parseFloat(zoom.value)); });
		var useButton = h('button', { class: 'btn primary', text: t('use_photo') });
		var scrim = h('div', { class: 'scrim', onclick: close });
		var dialog = h('div', { class: 'dialog surface', role: 'dialog', 'aria-label': t('replace_photo') }, [
			h('h3', { text: t('replace_photo') }), stage, h('p', { class: 'hint', text: t('crop_hint') }), zoom,
			h('div', { class: 'row' }, [h('button', { class: 'btn', text: t('cancel'), onclick: close }), useButton])
		]);
		function close() { dialog.remove(); scrim.remove(); URL.revokeObjectURL(url); if (floating === dialog) floating = null; }
		ui.layer.appendChild(scrim);
		ui.layer.appendChild(dialog);
		floating = dialog;
		dialog.scrim = scrim;
		redraw();
		useButton.focus();
		useButton.addEventListener('click', function () {
			var out = document.createElement('canvas');
			out.width = outW;
			out.height = outH;
			draw(out, outW, outH);
			var png = /png|gif/.test(file.type);
			useButton.disabled = true;
			useButton.textContent = t('uploading');
			out.toBlob(function (blob) {
				blob.name = png ? 'photo.png' : 'photo.jpg';
				working(1);
				call('editor_upload', {}, blob).then(function (uploaded) {
					working(-1);
					close();
					return usePhoto(field, uploaded.url);
				}, function (e) { working(-1); useButton.disabled = false; useButton.textContent = t('use_photo'); failed(e); });
			}, png ? 'image/png' : 'image/jpeg', 0.86);
		});
	}
	function swapImage(el, src) {
		el.style.transition = reduceMotion ? '' : 'opacity .25s';
		el.style.opacity = '0.25';
		var pre = new Image();
		pre.onload = pre.onerror = function () { el.setAttribute('src', src); el.style.opacity = ''; };
		pre.src = src;
	}
	function usePhoto(field, url) {
		if (field.pending) { field.el.setAttribute('data-raster-value', url); swapImage(field.el, url); return; }
		var before = field.item ? (field.item.info.values || {})[field.info.field] : field.info.value;
		var beforeSrc = field.el.getAttribute(field.attr || 'src');
		return saveValue(field, url).then(function () {
			swapImage(field.el, url);
			tick(field.el);
			toast(t('photo_changed'), function () {
				return saveValue(field, before || '').then(function () { swapImage(field.el, beforeSrc); });
			});
		}, failed);
	}

	// ##The page panel
	var panel = null;
	function panelOpen() { return !!panel; }
	function togglePanel() {
		if (panel) { panel.remove(); panel = null; return; }
		var pageFields = {}, order = [];
		fields.slice().sort(function (a, b) { return a.el.compareDocumentPosition(b.el) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1; }).forEach(function (f) {
			if (f.item) return;
			if (!pageFields[f.key]) { pageFields[f.key] = []; order.push(f.key); }
			pageFields[f.key].push(f);
		});
		var hiddenFields = Object.keys(marks).filter(function (id) { return marks[id].kind === 'field' && marks[id].hidden; }).map(function (id) { return marks[id]; });
		var seen = {};
		var fieldsSection = h('section', {}, [h('h3', { text: t('page_fields') })]);
		order.forEach(function (key) {
			var f = pageFields[key][0];
			seen[key] = true;
			fieldsSection.appendChild(h('div', { class: 'entry' }, [
				h('div', {}, [h('div', { class: 'name', text: human(f.info.field) }), h('div', { class: 'sub', text: f.info.type === 'sitepage' ? t('site_wide') : '' })]),
				h('button', { class: 'link', text: t('show_me'), onclick: function () { togglePanel(); reveal(f); } })
			]));
		});
		hiddenFields.forEach(function (info) {
			var key = 'page:' + info.type + ':' + info.field;
			if (seen[key]) return;
			seen[key] = true;
			var input = h(info.rich ? 'textarea' : 'input', { class: 'in', type: 'text' });
			input.value = info.value;
			var status = h('span', { class: 'sub muted', text: (info.type === 'sitepage' ? t('site_wide') + ' · ' : '') + t('not_on_page') });
			input.addEventListener('change', function () {
				var before = info.value, field = { info: info, el: input };
				saveValue(field, input.value).then(function () {
					tick(input);
					toast(t('changed', { field: human(info.field).toLowerCase() }), function () { return saveValue(field, before).then(function () { input.value = before; }); });
				}, failed);
			});
			fieldsSection.appendChild(h('label', { class: 'field' }, [human(info.field), input, status]));
		});
		if (!order.length && !hiddenFields.length) fieldsSection.appendChild(h('p', { class: 'muted', text: t('nothing_here') }));

		var listsSection = h('section', {}, [h('h3', { text: t('lists') })]);
		var byName = {};
		lists.forEach(function (list) { byName[list.info.collection] = byName[list.info.collection] || list; });
		Object.keys(byName).forEach(function (name) {
			var mine = Object.keys(items).map(function (id) { return items[id]; }).filter(function (i) { return i.info.collection === name; });
			var hidden = mine.filter(function (i) { return itemState(i.info) !== 'live'; }).length;
			listsSection.appendChild(h('div', { class: 'entry' }, [
				h('div', {}, [h('div', { class: 'name', text: human(name) }), h('div', { class: 'sub', text: (mine.length === 1 ? t('one_item') : t('items', { n: mine.length })) + (hidden ? ' · ' + t('hidden_count', { n: hidden }) : '') })]),
				h('button', { class: 'link', text: t('add'), onclick: function () {
					togglePanel();
					if (!editing) setEditing(true, true);
					var ghost = ghosts.filter(function (g) { return g.list.info.collection === name; })[0];
					if (ghost) { ghost.el.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' }); startNew(ghost); }
				} })
			]));
		});
		if (!lists.length) listsSection.hidden = true;

		var historySection = h('section', {}, [h('h3', { text: t('history') }), h('p', { class: 'muted', text: '…' })]);
		loadHistory(historySection);

		panel = h('aside', { class: 'panel surface', role: 'dialog', 'aria-label': t('this_page') }, [
			h('header', {}, [h('h2', { text: t('this_page') }), h('button', { class: 'close', title: t('close'), 'aria-label': t('close'), html: icon('x'), onclick: togglePanel })]),
			fieldsSection, listsSection, historySection,
			h('section', {}, [h('button', { class: 'link', text: t('log_out'), onclick: function () {
				call('logout', {}).then(function () { store('raster-editing', null); location.reload(); }, failed);
			} })])
		]);
		ui.layer.appendChild(panel);
		var closeButton = panel.querySelector('.close');
		if (closeButton) closeButton.focus();
	}
	function reveal(field) {
		if (!editing) setEditing(true, true);
		field.el.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
		field.el.classList.add('raster-pulse');
		setTimeout(function () { field.el.classList.remove('raster-pulse'); if (field.kind !== 'image') field.el.focus(); }, 500);
	}
	function loadHistory(section) {
		call('editor_history', { type: C.page.type }).then(function (result) {
			var list = result.revisions || [];
			section.innerHTML = '';
			section.appendChild(h('h3', { text: t('history') }));
			if (list.length < 2) { section.appendChild(h('p', { class: 'muted', text: t('no_history') })); return; }
			list.slice(0, 12).forEach(function (rev, i) {
				var older = list[i + 1];
				var changed = older ? Object.keys(rev.fields).filter(function (k) { return String(rev.fields[k]) !== String(older.fields[k]); }).map(human) : [];
				var entry = h('div', { class: 'entry' }, [
					h('div', {}, [h('div', { class: 'name', text: i === 0 ? t('current') : when(rev.updated_at) }), h('div', { class: 'sub', text: changed.join(', ') })]),
					i === 0 ? null : h('button', { class: 'link', text: t('restore'), onclick: function () { restore(rev, list[0]); } })
				]);
				section.appendChild(entry);
			});
		}, function () { section.hidden = true; });
	}
	function restore(rev, current) {
		working(1);
		call('editor_restore', { type: C.page.type, slug: C.page.slug, revision: rev.revision }).then(function () {
			store('raster-flash', JSON.stringify({ text: t('restored', { when: when(rev.updated_at) }), type: C.page.type, slug: C.page.slug, revision: current.revision }));
			location.reload();
		}, function (e) { working(-1); failed(e); });
	}

	// ##Hello
	var bubble = null;
	function closeBubble() { if (bubble) { bubble.remove(); bubble = null; } }
	function welcome() {
		if (store('raster-welcomed')) return;
		store('raster-welcomed', '1');
		var name = C.user.name ? t('hello', { name: C.user.name }) : t('hello_anon');
		bubble = h('div', { class: 'bubble surface', role: 'status', onclick: closeBubble }, [h('strong', { text: name }), h('span', { text: fields.length ? t('welcome') : t('nothing_here') })]);
		ui.layer.appendChild(bubble);
		setTimeout(glow, 700);
		setTimeout(closeBubble, 7000);
	}
	function flash() {
		var raw = store('raster-flash');
		if (!raw) return;
		store('raster-flash', null);
		try {
			var f = JSON.parse(raw);
			toast(f.text, f.revision ? function () { return call('editor_restore', { type: f.type, slug: f.slug, revision: f.revision }).then(function () { location.reload(); }); } : null);
		} catch (e) {}
	}

	function start() {
		var look = readLook();
		pageStyles(look);
		scan();
		buildChrome(look);
		refreshBadges();
		if (store('raster-editing')) setEditing(true, true);
		welcome();
		flash();
		window.addEventListener('beforeunload', function (e) { if (busy > 0) { e.preventDefault(); e.returnValue = ''; } });
		window.RasterEditor = { fields: fields, items: items, lists: lists, edit: function (on) { setEditing(on !== false); } };
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
