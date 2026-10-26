// The in-page editor in a real browser: node tests/editor-browser.js
//
// Optional (it needs Playwright: npm i playwright, and a Chromium it can
// find; set CHROMIUM=/path/to/chrome if needed). Starts the demo café on a
// fresh database, logs in as an editor and uses the editor the way a person
// would: edit mode, typing, undo, adding an item with a photo, hiding,
// deleting and undoing, a keyboard-only pass, Enter in a paragraph field,
// the page panel, a multilingual site's /ro/ page (RASTER_TEST_ONLY=multilingual
// runs only that one), and the editor's words fitting its buttons in every
// language (RASTER_TEST_ONLY=labels-fit). Screenshots go to tests/screens/.
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const net = require('net');
const crypto = require('crypto');

const root = path.resolve(__dirname, '..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'raster-editor-'));
const screens = path.join(__dirname, 'screens');
fs.mkdirSync(screens, { recursive: true });
const env = Object.assign({}, process.env, { RASTER_APP: 'demo', RASTER_DB: path.join(tmp, 'cafe.sqlite'), RASTER_MAIL: 'log://' + path.join(tmp, 'mail'), RASTER_SECRET: 'test-secret' });
// a post form's signed time stamp, made a minute ago, as a browser that
// fetched the form would send it (tests/db.php stamp_for_tests)
function stamp() {
	const time = Math.floor(Date.now() / 1000) - 60;
	return time + '.' + crypto.createHmac('sha256', 'test-secret').update('raster_ts|' + time).digest('hex');
}

let failures = 0, passed = 0;
function check(condition, message) { if (condition) passed++; else { failures++; console.log('  ✗ ' + message); } }
function port() { return new Promise(r => { const s = net.createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); }); }
const host = page => page.locator('[data-raster-host]');
const inShadow = (page, fn, arg) => host(page).evaluate(fn, arg);

// The editor on a multilingual site (#200): the starter in English and
// Romanian, written to its the_events.php for the test, with a page of two
// fields. An English editor on /ro/ edits Romanian with English words.
async function multilingual(browser) {
	const app = path.join(root, 'application');
	const view = path.join(app, 'views/default/zzml.html');
	const events = path.join(app, 'config/the_events.php');
	const kept = fs.readFileSync(events, 'utf8');
	const mlEnv = Object.assign({}, env, { RASTER_APP: 'application', RASTER_DB: path.join(tmp, 'ml.sqlite'), RASTER_ENV: 'development' });
	fs.writeFileSync(view, '<!doctype html><html><head><title>About</title></head><body><main><h1><!-- print.cms.zzml_headline -->Our story<!-- /print.cms.zzml_headline --></h1>'
		+ '<p class="intro"><!-- print.cms.zzml_intro -->Coffee since 2001.<!-- /print.cms.zzml_intro --></p></main></body></html>');
	fs.writeFileSync(events, (kept.startsWith('<?php') ? kept : '<?php\n') + "\nconfig::set('languages')->to(array('en', 'ro'));\nconfig::set('multilingual')->to(true);\n");
	const p = await port();
	const base = 'http://127.0.0.1:' + p;
	let server = null;
	try {
		execFileSync('php', [path.join(root, 'bin/raster'), 'user', 'ana@site.test', '--role=editor', '--password=ana password', '--name=Ana'], { env: mlEnv });
		server = spawn('php', ['-S', '127.0.0.1:' + p, path.join(root, 'index.php')], { cwd: root, env: mlEnv, stdio: 'ignore' });
		await new Promise(r => setTimeout(r, 600));
		// the first view makes the fields, and their Romanian columns
		await fetch(base + '/zzml');
		const context = await browser.newContext({ viewport: { width: 1280, height: 860 }, locale: 'en-GB' });
		const page = await context.newPage();
		const errors = [];
		page.on('pageerror', e => errors.push(e.message));
		await page.goto(base + '/login');
		await page.fill('input[name=login]', 'ana@site.test');
		await page.fill('input[name=password]', 'ana password');
		await Promise.all([page.waitForNavigation(), page.click('form button')]);
		// the default language's page has no badge: nothing to fall back to
		await page.goto(base + '/zzml');
		await page.waitForTimeout(500);
		check(await inShadow(page, h => h.shadowRoot.querySelector('.dock .lang') === null), 'no language badge on the default language');
		await page.goto(base + '/ro/zzml');
		await page.waitForTimeout(500);
		// a short badge, so the dock fits a phone; the sentence is its title
		const badge = await inShadow(page, h => { const b = h.shadowRoot.querySelector('.dock .lang'); return { text: b.textContent, title: b.title, label: b.getAttribute('aria-label') }; });
		check(badge.text === 'RO', 'the dock says the page\'s language in two letters: ' + badge.text);
		check(badge.title === 'editing: RO (falls back to EN)' && badge.label === badge.title, 'and the sentence as its title and label: ' + badge.title);
		await page.setViewportSize({ width: 360, height: 740 });
		await page.waitForTimeout(300);
		check(await inShadow(page, h => { const d = h.shadowRoot.querySelector('.dock').getBoundingClientRect(); return [...h.shadowRoot.querySelectorAll('.dock > *')].every(el => { const r = el.getBoundingClientRect(); return r.width === 0 || (r.left >= d.left - 1 && r.right <= d.right + 1 && r.right <= 361); }); }), 'on a phone every button of the dock is in view');
		check(await inShadow(page, h => getComputedStyle(h.shadowRoot.querySelector('.dock .lang')).display === 'none'), 'on a phone no language badge: the dock keeps its room for buttons');
		await page.setViewportSize({ width: 1280, height: 860 });
		await page.waitForTimeout(300);
		check(await inShadow(page, h => h.shadowRoot.querySelector('.dock .pill.primary').textContent) === 'Edit', 'an English editor gets English words on /ro/');
		check(await page.locator('h1[data-raster-untranslated]').count() === 1, 'an untranslated field is marked');
		check(await page.locator('h1').textContent() === 'Our story', 'and shows the English');
		// a save on /ro/ is the Romanian
		await page.keyboard.press('e');
		await page.waitForTimeout(300);
		await page.locator('h1').click();
		await page.keyboard.press('Control+A');
		await page.keyboard.type('Povestea noastră');
		await page.keyboard.press('Enter');
		await page.waitForTimeout(800);
		check((await (await fetch(base + '/ro/zzml')).text()).includes('<h1>Povestea noastră</h1>'), 'the Romanian is saved');
		check((await (await fetch(base + '/zzml')).text()).includes('<h1>Our story</h1>'), 'the English stays');
		check(await page.locator('h1[data-raster-untranslated]').count() === 0, 'translated now');
		check(await page.locator('p.intro[data-raster-untranslated]').count() === 1 && await page.locator('p.intro').textContent() === 'Coffee since 2001.', 'the other field still shows English');
		await page.screenshot({ path: path.join(screens, 'multilingual.png') });
		// undo puts back what the Romanian held, nothing, never the English it showed
		await page.mouse.click(5, 5);
		await page.keyboard.press('Control+z');
		await page.waitForTimeout(800);
		await page.reload();
		await page.waitForTimeout(500);
		check(await page.locator('h1[data-raster-untranslated]').count() === 1 && await page.locator('h1').textContent() === 'Our story', 'after undo the Romanian is empty again, and the English shows');
		// the editor's own words follow the site's base language, with no picker
		check(await inShadow(page, h => h.shadowRoot.querySelector('.dock .langs') === null), 'no language picker in the dock');
		check(await inShadow(page, h => h.shadowRoot.querySelector('.dock .lang').title) === 'editing: RO (falls back to EN)', 'the dock in the base language\'s words');
		check(errors.length === 0, 'no script errors on /ro/: ' + errors.join('; '));
		await context.close();
	} finally {
		if (server) server.kill();
		fs.writeFileSync(events, kept);
		fs.rmSync(view, { force: true });
	}
}

// The editor's words fit its controls, in every language it speaks
// (RASTER_TEST_ONLY=labels-fit runs only this; RASTER_LABELS_LANGS=ro,de
// only those languages, next to English). The editor was laid out with
// English words, and a longer word in another language can be cut off,
// wrap onto a second line or push a button off a phone's screen. For each
// language, on a phone (390px) and a desktop (1280px), it opens every part
// of the editor that has words on it and checks each label: it stays inside
// its box, inside the part it is in and inside the screen, and it takes no
// more lines than the English does there. Text that is meant to flow onto
// more lines (messages, hints, the panel's notes) is not a label and isn't
// checked; neither are words only screen readers and tooltips say.
const FLOWING = '.hint, .toast > span, p.muted, .sub, .bubble, .bubble *';
// the parts of the editor that have words, in its shadow root
const PARTS = { dock: '.dock', menu: '.menu', panel: '.panel', handle: '.float.handle', popover: '.float.popover', dialog: '.dialog', ghost: '.ghost-label', badge: '.badge', photo: '.photo-button', drop: '.drop', toast: '.toast' };
// in the page: each label of the parts open now, with where it is and how many lines it takes
function measureLabels(h, { parts, flowing }) {
	const out = [], vw = document.documentElement.clientWidth;
	for (const [name, selector] of Object.entries(parts)) {
		h.shadowRoot.querySelectorAll(selector).forEach((part, n) => {
			const box = part.getBoundingClientRect();
			if (!box.width || getComputedStyle(part).display === 'none') return;
			const path = el => { const steps = []; for (; el && el !== part; el = el.parentElement) steps.unshift(el.tagName.toLowerCase() + ':' + [...el.parentElement.children].indexOf(el)); return name + n + '/' + steps.join('/'); };
			if (box.left < -1 || box.right > vw + 1) out.push({ id: path(part), name, text: part.innerText.replace(/\s+/g, ' ').slice(0, 60), problem: 'goes past the screen\'s edge' });
			for (const el of [part, ...part.querySelectorAll('*')]) {
				const r = el.getBoundingClientRect();
				if (!r.width || !el.getClientRects().length || el.closest('svg')) continue;
				const words = [...el.childNodes].filter(c => c.nodeType === 3 && c.textContent.trim());
				const text = words.map(c => c.textContent.trim()).join(' ');
				// pushed out of the part, or off the screen (sideways: the panel scrolls down)
				if (el !== part && (r.left < box.left - 1 || r.right > box.right + 1)) { out.push({ id: path(el), name, text: text || el.getAttribute('aria-label') || el.tagName, problem: 'is pushed out of its ' + name + ', by ' + Math.round(Math.max(box.left - r.left, r.right - box.right)) + 'px' }); continue; }
				if (el !== part && (r.left < -1 || r.right > vw + 1)) { out.push({ id: path(el), name, text: text || el.tagName, problem: 'is pushed off the screen' }); continue; }
				if (!words.length || el.matches(flowing)) continue;
				const range = document.createRange();
				const rects = words.flatMap(w => { range.selectNodeContents(w); return [...range.getClientRects()].filter(q => q.width > 0); });
				const lines = new Set(rects.map(q => Math.round(q.top))).size;
				const style = getComputedStyle(el);
				const clipped = style.overflowX !== 'visible' && el.scrollWidth > el.clientWidth + 1 || rects.some(q => q.right > r.right + 1 || q.left < r.left - 1);
				out.push({ id: path(el), name, text, lines, problem: clipped ? 'is cut off' : '' });
			}
		});
	}
	return out;
}
async function labelsFit(browser) {
	const langs = ['en'].concat(fs.readdirSync(path.join(root, 'system/models/cms/editor/lang')).map(f => f.replace(/\.php$/, '')).sort())
		.filter(l => l === 'en' || !process.env.RASTER_LABELS_LANGS || process.env.RASTER_LABELS_LANGS.split(',').includes(l));
	const fitEnv = Object.assign({}, env, { RASTER_DB: path.join(tmp, 'labels.sqlite') });
	execFileSync('php', [path.join(root, 'bin/raster'), 'user', 'lena@cafe.test', '--role=editor', '--password=lena password', '--name=Lena'], { env: fitEnv });
	const p = await port();
	const base = 'http://127.0.0.1:' + p;
	const server = spawn('php', ['-S', '127.0.0.1:' + p, path.join(root, 'index.php')], { cwd: root, env: fitEnv, stdio: 'ignore' });
	process.on('exit', () => server.kill());
	try {
		await new Promise(r => setTimeout(r, 600));
		await fetch(base + '/menu');
		// logged in once, in English: a page's history to restore, a hidden
		// dish and one that goes live later, so their badges show
		const setup = await browser.newContext({ viewport: { width: 1280, height: 860 }, reducedMotion: 'reduce' });
		const page = await setup.newPage();
		await page.goto(base + '/login');
		await page.fill('input[name=login]', 'lena@cafe.test');
		await page.fill('input[name=password]', 'lena password');
		await Promise.all([page.waitForNavigation(), page.click('form button')]);
		await page.goto(base + '/menu');
		await page.waitForTimeout(400);
		await page.keyboard.press('e');
		await page.waitForTimeout(300);
		for (const dish of ['Cortado', 'Espresso']) {
			await page.locator('.raster-ghost').first().click();
			await page.keyboard.type(dish);
			await inShadow(page, h => h.shadowRoot.querySelector('.float.handle .text.primary').click());
			await page.waitForTimeout(900);
		}
		// by name again: Cortado, Espresso, Flat white
		await page.reload();
		await page.waitForTimeout(400);
		if (!await page.evaluate(() => document.documentElement.classList.contains('raster-editing'))) await page.keyboard.press('e');
		for (const heading of ['Our menu', 'Menu']) {
			await page.locator('h1').click();
			await page.keyboard.press('Control+A');
			await page.keyboard.type(heading);
			await page.keyboard.press('Enter');
			await page.waitForTimeout(700);
		}
		const handleOf = async n => { await page.mouse.move(2, 2); await page.locator('[data-raster-item]').nth(n).focus(); await page.keyboard.press('Enter'); await page.waitForTimeout(200); };
		await handleOf(1);
		await inShadow(page, h => h.shadowRoot.querySelectorAll('.handle button')[2].click());
		await page.waitForTimeout(700);
		await handleOf(2);
		await inShadow(page, h => h.shadowRoot.querySelectorAll('.handle button')[3].click());
		await page.waitForTimeout(200);
		await inShadow(page, h => { const i = h.shadowRoot.querySelector('.popover input'); i.value = (new Date().getFullYear() + 1) + '-09-30T09:00'; h.shadowRoot.querySelector('.popover .btn.primary').click(); });
		await page.waitForTimeout(700);
		check(await inShadow(page, h => h.shadowRoot.querySelectorAll('.badge').length) === 2, 'labels-fit: a hidden dish and a later one, for their badges');
		const login = await setup.storageState();
		await setup.close();

		const english = {};
		for (const lang of langs) for (const phone of [true, false]) {
			const width = phone ? 390 : 1280;
			const context = await browser.newContext(Object.assign({ storageState: login, reducedMotion: 'reduce', extraHTTPHeaders: { 'Raster-Editor-Language': lang } },
				phone ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } : { viewport: { width: 1280, height: 860 } }));
			const tab = await context.newPage();
			const errors = [];
			tab.on('pageerror', e => errors.push(e.message));
			tab.on('filechooser', () => {});
			const seen = [];
			// waits for a part of the editor to open (a check in the page, or a selector in its shadow root)
			const waitFor = async test => {
				const fn = typeof test === 'string' ? (h, sel) => !!h.shadowRoot.querySelector(sel) : test;
				for (let t = 0; t < 40; t++) { if (await inShadow(tab, fn, typeof test === 'string' ? test : undefined)) return true; await tab.waitForTimeout(50); }
				return false;
			};
			const look = async (...names) => {
				for (const n of names) if (!['badge', 'ghost'].includes(n)) await waitFor(PARTS[n]);
				await tab.waitForTimeout(60);
				seen.push(...await inShadow(tab, measureLabels, { parts: Object.fromEntries(names.map(n => [n, PARTS[n]])), flowing: FLOWING }));
			};
			const click = async (selector, i) => {
				await waitFor(selector);
				const found = await inShadow(tab, (h, a) => { const el = h.shadowRoot.querySelectorAll(a.selector)[a.i || 0]; if (el) el.click(); return !!el; }, { selector, i });
				if (!found) throw new Error('labels-fit: no ' + selector + ' to click (' + lang + ', ' + width + 'px); open: ' + await inShadow(tab, h => [...h.shadowRoot.querySelectorAll('.layer > *, .float')].map(e => e.className).join(', ')));
			};
			const item = tab.locator('[data-raster-item]').first();
			// a press outside closes a popover and saves nothing
			const shut = () => tab.evaluate(() => document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true })));
			const activate = async () => {
				if (phone) await item.evaluate(el => el.dispatchEvent(new MouseEvent('click', { bubbles: true })));
				else { await tab.mouse.move(2, 2); await item.focus(); await tab.keyboard.press('Enter'); }
				await waitFor('.float.handle');
			};
			await tab.goto(base + '/menu');
			await tab.waitForTimeout(400);
			check(await inShadow(tab, h => h.shadowRoot.querySelector('.dock .pill.primary').textContent.trim()) !== '' && (lang === 'en' || await inShadow(tab, h => h.shadowRoot.querySelector('.dock .pill.primary').textContent) !== 'Edit'), 'labels-fit: the editor speaks ' + lang);
			// the dock, not editing and editing; the admin menu; the page panel
			await look('dock');
			await click('.dock [aria-haspopup]');
			await look('menu');
			await click('.dock [aria-haspopup]');
			await tab.evaluate(() => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'e', bubbles: true })));
			await look('dock', 'ghost', 'badge');
			await click('.dock .pill:not(.primary):not([aria-haspopup])');
			// the history comes after the panel opens
			await waitFor(h => !!h.shadowRoot.querySelector('.panel') && !h.shadowRoot.querySelector('.panel').innerText.includes('…'));
			await look('panel');
			await click('.panel .close');
			// an item's handle, its details and its schedule
			await activate();
			await look('handle');
			await click('.handle button', 0);
			await look('popover');
			await shut();
			await activate();
			await click('.handle button', 3);
			await look('popover');
			await shut();
			// hiding a dish says so, with an undo
			await activate();
			await click('.handle button', 2);
			await waitFor('.toast .undo');
			await look('toast');
			await click('.toast .undo');
			for (let t = 0; t < 100 && await item.getAttribute('data-raster-state') !== 'live'; t++) await tab.waitForTimeout(100);
			check(await item.getAttribute('data-raster-state') === 'live', 'labels-fit: the undo shows the dish again (' + lang + ', ' + width + 'px)');
			// a photo: its button (a mouse's), a picture dragged over it, framing a new one
			const photo = tab.locator('img[data-raster-field]').first();
			if (!phone) { await tab.mouse.move(2, 2); await photo.focus(); await look('photo'); }
			await photo.evaluate(img => { const d = new DataTransfer(); d.items.add(new File(['x'], 'a.jpg', { type: 'image/jpeg' })); img.dispatchEvent(new DragEvent('dragover', { dataTransfer: d, bubbles: true, cancelable: true })); });
			await look('drop');
			await photo.evaluate(img => img.dispatchEvent(new DragEvent('dragleave', { bubbles: true })));
			const [chooser] = await Promise.all([tab.waitForEvent('filechooser', { timeout: 5000 }).catch(() => null), photo.click({ force: true })]);
			if (chooser) { await chooser.setFiles(path.join(root, 'demo/views/cafe/img/about.jpg')); await look('dialog'); await click('.dialog .btn', 0); }
			check(!!chooser, 'labels-fit: the photo dialog opens (' + lang + ', ' + width + 'px)');
			// a new item's Cancel and Add
			await tab.locator('.raster-ghost').first().click();
			await look('handle');
			await click('.float.handle .text', 0);

			for (const label of seen) {
				const where = lang + ' at ' + width + 'px, ' + label.name + ': "' + label.text + '" ';
				if (lang === 'en') english[width + label.id] = label.lines;
				if (label.problem) check(false, where + label.problem);
				else if (label.lines > (english[width + label.id] || 1)) check(false, where + 'takes ' + label.lines + ' lines, the English ' + (english[width + label.id] || 1));
				else check(true);
			}
			if (process.env.RASTER_LABELS_DEBUG) console.log(lang, width, JSON.stringify(seen.reduce((n, l) => (n[l.name] = (n[l.name] || 0) + 1, n), {})));
			check(seen.length > 30, 'labels-fit: the labels were found (' + lang + ', ' + width + 'px): ' + seen.length);
			check(errors.length === 0, 'labels-fit: no script errors (' + lang + ', ' + width + 'px): ' + errors.join('; '));
			await context.close();
		}
	} finally {
		server.kill();
	}
}

(async () => {
	const p = await port();
	const base = 'http://127.0.0.1:' + p;
	execFileSync('php', [path.join(root, 'bin/raster'), 'user', 'staff@cafe.test', '--role=editor', '--password=staff password', '--name=Sam'], { env });
	// what the café takes bookings for
	execFileSync('php', [path.join(root, 'demo/seed.php')], { env });
	// the coming Tuesday and Wednesday: sitting days, within the two months guests may book
	const ahead = (weekday, weeks) => { const d = new Date(); d.setUTCDate(d.getUTCDate() + ((weekday - d.getUTCDay() + 7) % 7 || 7) + 7 * (weeks - 1)); return d.toISOString().slice(0, 10); };
	const wednesday = ahead(3, 2);
	// the staff agenda shows today and the 6 days after it, so its booking is
	// made tomorrow, or the day after when tomorrow is Monday (the café is
	// closed): "next Tuesday" is 7 days away on a Tuesday, past the agenda
	const soon = (() => { const d = new Date(); d.setUTCDate(d.getUTCDate() + (d.getUTCDay() === 0 ? 2 : 1)); return d.toISOString().slice(0, 10); })();
	const server = spawn('php', ['-S', '127.0.0.1:' + p, path.join(root, 'index.php')], { cwd: root, env, stdio: 'ignore' });
	// stopped however the test ends, also when the browser fails to start
	process.on('exit', () => server.kill());
	await new Promise(r => setTimeout(r, 600));
	for (const url of ['/', '/menu', '/about']) await fetch(base + url);
	const browser = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {});
	const errors = [];
	try {
		// the café, unless only one other part runs
		cafe: {
		if (process.env.RASTER_TEST_ONLY) break cafe;
		const context = await browser.newContext({ viewport: { width: 1280, height: 860 } });
		const page = await context.newPage();
		page.on('pageerror', e => errors.push(e.message));
		// file choosers stay intercepted all along: a waitForEvent alone turns
		// interception on without waiting, so a key pressed right after it can
		// open the chooser before then, and the event never comes
		page.on('filechooser', () => {});
		await page.goto(base + '/login');
		await page.fill('input[name=login]', 'staff@cafe.test');
		await page.fill('input[name=password]', 'staff password');
		await Promise.all([page.waitForNavigation(), page.click('form button')]);
		await page.waitForTimeout(900);
		check(await inShadow(page, h => !!h.shadowRoot.querySelector('.bubble')), 'the welcome bubble');
		await page.screenshot({ path: path.join(screens, 'welcome.png') });

		// edit a page field in place, then undo it
		await page.goto(base + '/');
		await page.keyboard.press('e');
		await page.waitForTimeout(300);
		check(await page.evaluate(() => document.documentElement.classList.contains('raster-editing')), 'E starts editing');
		await page.locator('h1').click();
		await page.keyboard.press('Control+A');
		await page.keyboard.type('Good coffee, slower mornings.');
		await page.keyboard.press('Enter');
		await page.waitForTimeout(700);
		let html = await (await fetch(base + '/')).text();
		check(html.includes('<h1>Good coffee, slower mornings.</h1>'), 'the headline is saved');
		await page.screenshot({ path: path.join(screens, 'saved.png') });
		await page.keyboard.press('Control+z');
		await page.waitForTimeout(800);
		html = await (await fetch(base + '/')).text();
		check(html.includes('<h1>Good coffee, slow mornings.</h1>'), 'undo puts it back');

		// Enter in a rich field that is itself a paragraph is a line break,
		// not a paragraph inside the paragraph (#114)
		const about = path.join(root, 'demo/views/cafe/about.html');
		const aboutView = fs.readFileSync(about, 'utf8');
		fs.writeFileSync(about, aboutView.replace("<h2><!-- print.i18n.about('opening_hours') -->", '<p class="lead"><!-- print.cms.lead -->We roast <em>our own</em> beans.<!-- /print.cms.lead --></p><ul class="features"><!-- print.cms.features --><li>Fast</li><li>Cheap</li><!-- /print.cms.features --></ul><article class="note"><!-- print.cms.note -->Fresh <em>bread</em> daily.<!-- /print.cms.note --></article><h2><!-- print.i18n.about(\'opening_hours\') -->'));
		try {
			await page.goto(base + '/about');
			await page.waitForTimeout(500);
			const lead = page.locator('p.lead');
			check(await lead.getAttribute('data-raster-edit') === 'rich', 'the lead is a rich field');
			await lead.click();
			await page.keyboard.press('Control+End');
			await page.keyboard.press('Enter');
			await page.keyboard.type('Second line.');
			await page.mouse.click(5, 5);
			await page.waitForTimeout(900);
			html = await (await fetch(base + '/about')).text();
			check(html.includes('<p class="lead">We roast <em>our own</em> beans.<br>Second line.</p>'), 'Enter saved a line break: ' + (html.match(/<p class="lead">[\s\S]*?<h2>/) || [''])[0]);
			await page.reload();
			await page.waitForTimeout(500);
			check(await page.locator('p.lead[data-raster-field]').count() === 1 && await page.locator('main > p:empty').count() === 0, 'after a reload the lead is still one field, with no empty paragraph');
			// a pasted paragraph is unwrapped into the line too
			await lead.click();
			await page.keyboard.press('Control+End');
			await lead.evaluate(el => {
				const data = new DataTransfer();
				data.setData('text/html', '<p>Pasted one.</p><p>Pasted two.</p>');
				data.setData('text/plain', 'Pasted one. Pasted two.');
				el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
			});
			await page.mouse.click(5, 5);
			await page.waitForTimeout(900);
			html = await (await fetch(base + '/about')).text();
			check(/<p class="lead">We roast <em>our own<\/em> beans\.<br>Second line\.(<br>)?Pasted one\.<br>Pasted two\.<\/p>/.test(html), 'a pasted paragraph stays in the line: ' + (html.match(/<p class="lead">[\s\S]*?<h2>/) || [''])[0]);
			// and so are a pasted list, heading and quote, at any depth
			await lead.click();
			await page.keyboard.press('Control+End');
			await lead.evaluate(el => {
				const data = new DataTransfer();
				data.setData('text/html', '<ul><li>One</li><li>Two</li></ul><h2>Head</h2><blockquote><p>Quote</p></blockquote>');
				data.setData('text/plain', 'One Two Head Quote');
				el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
			});
			await page.mouse.click(5, 5);
			await page.waitForTimeout(900);
			html = await (await fetch(base + '/about')).text();
			check(/<p class="lead">We roast <em>our own<\/em> beans\.<br>Second line\.(<br>)?Pasted one\.<br>Pasted two\.(<br>)?One<br>Two<br>Head<br>Quote<\/p>/.test(html), 'a pasted list, heading and quote become lines: ' + (html.match(/<p class="lead">[\s\S]*?<h2>/) || [''])[0]);
			await page.reload();
			await page.waitForTimeout(500);
			check(await page.locator('p.lead[data-raster-field]').count() === 1 && await page.locator('main > p:empty').count() === 0, 'after a pasted list the lead is still one field');
			// a rich field that is a list: Enter adds a bullet, as the browser does
			const features = page.locator('ul.features');
			await features.locator('li').last().click();
			await page.keyboard.press('Control+End');
			await page.keyboard.press('Enter');
			await page.keyboard.type('Third');
			await page.mouse.click(5, 5);
			await page.waitForTimeout(900);
			html = await (await fetch(base + '/about')).text();
			check(html.includes('<ul class="features"><li>Fast</li><li>Cheap</li><li>Third</li></ul>'), 'Enter in a list field adds a bullet: ' + (html.match(/<ul class="features">[\s\S]*?<\/ul>/) || [''])[0]);
			// a field printed straight into an <article> is wrapped in a span
			// by the editor, but takes paragraphs and lists, as the article does
			const note = page.locator('article.note [data-raster-field]');
			await note.click();
			await page.waitForTimeout(200);
			check(await inShadow(page, h => !!h.shadowRoot.querySelector('.format button[aria-label=List]')), 'a field in an article offers a list');
			// moving from one rich field to another keeps the formatting bar:
			// the first field's leaving must not take down the second's bar
			const barFor = () => inShadow(page, h => !!h.shadowRoot.querySelector('.float.format'));
			await page.locator('p.lead[data-raster-field]').click();
			await page.waitForTimeout(200);
			check(await barFor(), 'the bar on the lead');
			await note.click();
			await page.waitForTimeout(200);
			check(await barFor(), 'the bar on the note, after the lead');
			await page.locator('p.lead[data-raster-field]').click();
			await page.waitForTimeout(200);
			check(await barFor(), 'the bar on the lead again, after the note');
			await note.click();
			await page.waitForTimeout(200);
			await page.keyboard.press('Control+End');
			await note.evaluate(el => {
				const data = new DataTransfer();
				data.setData('text/html', '<p>Pasted one.</p><p>Pasted two.</p>');
				data.setData('text/plain', 'Pasted one. Pasted two.');
				el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
			});
			await page.mouse.click(5, 5);
			await page.waitForTimeout(900);
			html = await (await fetch(base + '/about')).text();
			// the browser runs the first pasted paragraph on into the line it lands in
			check(/<article class="note">Fresh <em>bread<\/em> daily\.(<p>)?Pasted one\.(<\/p>)?<p>Pasted two\.<\/p><\/article>/.test(html), 'a paragraph pasted into a field in an article stays a paragraph: ' + (html.match(/<article class="note">[\s\S]*?<\/article>/) || [''])[0]);
		} finally {
			fs.writeFileSync(about, aboutView);
		}

		// the same for an item's paragraph field, in a new item and in a copy (#114)
		const menuFile = path.join(root, 'demo/views/cafe/menu.html');
		const menuView = fs.readFileSync(menuFile, 'utf8');
		fs.writeFileSync(menuFile, menuView.replace('<!-- print.description -->Double ristretto, steamed milk.', '<!-- print.description -->Double <em>ristretto</em>, steamed milk.'));
		try {
			await page.goto(base + '/menu');
			await page.waitForTimeout(500);
			const card = page.locator('.raster-ghost').first();
			await card.click();
			await page.keyboard.type('Macchiato');
			await card.locator('[data-raster-name=description]').click();
			await page.keyboard.press('End');
			await page.keyboard.press('Enter');
			await page.keyboard.type('Second');
			await inShadow(page, h => [...h.shadowRoot.querySelectorAll('.handle button')].find(b => b.textContent === 'Add').click());
			await page.waitForTimeout(1000);
			html = await (await fetch(base + '/menu')).text();
			check(html.includes('<p>Double <em>ristretto</em>, steamed milk.<br>Second</p>'), 'Enter in a new item\'s paragraph field saved a line break: ' + (html.match(/Macchiato[\s\S]*?<p class="price">/) || [''])[0]);
			// a copy of an item: its fields know they are paragraphs too
			await page.reload();
			await page.waitForTimeout(500);
			const macchiato = page.locator('[data-raster-item]', { hasText: 'Macchiato' }).first();
			await macchiato.hover({ position: { x: 20, y: 20 }, force: true });
			await page.waitForTimeout(300);
			await inShadow(page, h => h.shadowRoot.querySelector('.handle button[aria-label=Duplicate]').click());
			await page.waitForTimeout(1000);
			const copy = page.locator('[data-raster-item]', { hasText: 'Macchiato' }).nth(1);
			await copy.locator('p[data-raster-field]').first().click();
			await page.keyboard.press('Control+End');
			await page.keyboard.press('Enter');
			await page.keyboard.type('Third');
			await page.mouse.click(5, 5);
			await page.waitForTimeout(900);
			html = await (await fetch(base + '/menu')).text();
			check(html.includes('<p>Double <em>ristretto</em>, steamed milk.<br>Second<br>Third</p>'), 'Enter in a copied item\'s paragraph field saved a line break: ' + (html.match(/<p>Double <em>ristretto<\/em>, steamed milk\.<br>Second[\s\S]*?<\/p>/g) || []).join(' | '));
			// the two Macchiatos go, so the rest of the run sees the menu it expects
			for (let i = 0; i < 2; i++) {
				await page.goto(base + '/menu');
				await page.waitForTimeout(500);
				await page.mouse.move(2, 2);
				await page.locator('[data-raster-item]', { hasText: 'Macchiato' }).first().hover({ position: { x: 20, y: 20 }, force: true });
				await page.waitForTimeout(300);
				await inShadow(page, h => h.shadowRoot.querySelector('.handle button[aria-label=Delete]').click());
				await page.waitForTimeout(900);
			}
			check(!(await (await fetch(base + '/menu')).text()).includes('Macchiato'), 'the Macchiatos are gone');
		} finally {
			fs.writeFileSync(menuFile, menuView);
		}
		// a new item's field printed straight into its <article> takes paragraphs
		fs.writeFileSync(menuFile, menuView.replace('<p><!-- print.description -->Double ristretto, steamed milk.<!-- /print.description --></p>', '<!-- print.description -->Double <em>ristretto</em>, steamed milk.<!-- /print.description -->'));
		try {
			await page.goto(base + '/menu');
			await page.waitForTimeout(500);
			const card = page.locator('.raster-ghost').first();
			await card.click();
			await page.keyboard.type('Lungo');
			const description = card.locator('[data-raster-name=description]');
			check(await description.evaluate(el => el.hasAttribute('data-raster-wrap') && el.parentNode.tagName === 'ARTICLE'), 'the new item\'s description is a wrapper in the article');
			await description.click();
			await page.keyboard.press('Control+End');
			await page.keyboard.press('Enter');
			await page.keyboard.type('Second');
			await inShadow(page, h => [...h.shadowRoot.querySelectorAll('.handle button')].find(b => b.textContent === 'Add').click());
			await page.waitForTimeout(1000);
			html = await (await fetch(base + '/menu')).text();
			check(/Lungo[\s\S]*?Double <em>ristretto<\/em>, steamed milk\.<p>Second<\/p>/.test(html) || /Lungo[\s\S]*?<p>Double <em>ristretto<\/em>, steamed milk\.<\/p><p>Second<\/p>/.test(html), 'Enter in a new item\'s field in an article saved a paragraph: ' + (html.match(/Lungo[\s\S]*?<p class="price">/) || [''])[0]);
			await page.mouse.move(2, 2);
			await page.locator('[data-raster-item]', { hasText: 'Lungo' }).first().hover({ position: { x: 20, y: 20 }, force: true });
			await page.waitForTimeout(300);
			await inShadow(page, h => h.shadowRoot.querySelector('.handle button[aria-label=Delete]').click());
			await page.waitForTimeout(900);
			check(!(await (await fetch(base + '/menu')).text()).includes('Lungo'), 'the Lungo is gone');
		} finally {
			fs.writeFileSync(menuFile, menuView);
		}

		// a typed field shows what was stored, the way the page prints it
		await page.goto(base + '/menu');
		await page.waitForTimeout(500);
		const price = page.locator('p.price [data-raster-field]').first();
		await price.click();
		await page.keyboard.press('Control+A');
		await page.keyboard.type('9.5');
		await page.keyboard.press('Enter');
		await page.waitForTimeout(700);
		check((await price.innerText()).trim() === '9.50', 'a price typed as 9.5 shows as 9.50, as the mock-up (14.50) prints it: ' + await price.innerText());
		await page.keyboard.press('Control+z');
		await page.waitForTimeout(700);
		check((await price.innerText()).trim() === '14.50', 'undo shows the old price: ' + await price.innerText());

		// add a dish from the ghost card, with a photo
		await page.goto(base + '/menu');
		await page.waitForTimeout(500);
		const ghost = page.locator('.raster-ghost').first();
		await ghost.click();
		await page.keyboard.type('Cortado');
		const [chooser] = await Promise.all([page.waitForEvent('filechooser'), ghost.locator('img').click()]);
		await chooser.setFiles(path.join(root, 'demo/views/cafe/img/about.jpg'));
		await page.waitForTimeout(600);
		check(await inShadow(page, h => !!h.shadowRoot.querySelector('.dialog canvas')), 'the photo framing dialog');
		await page.screenshot({ path: path.join(screens, 'frame.png') });
		await inShadow(page, h => h.shadowRoot.querySelector('.dialog .btn.primary').click());
		await page.waitForTimeout(1200);
		await inShadow(page, h => [...h.shadowRoot.querySelectorAll('.handle button')].find(b => b.textContent === 'Add').click());
		await page.waitForTimeout(1000);
		await page.reload();
		html = await page.content();
		check(/Cortado/.test(html) && /\/media\/\d{8}-[a-f0-9]{12}\.jpg/.test(html), 'the new dish and its photo are saved');

		// hide the first dish, delete the new one and undo
		const flatWhite = page.locator('[data-raster-item]', { hasText: 'Flat white' }).first();
		await flatWhite.hover({ position: { x: 20, y: 20 }, force: true });
		await page.waitForTimeout(300);
		await inShadow(page, h => h.shadowRoot.querySelector('.handle button[aria-label=Hide]').click());
		await page.waitForTimeout(800);
		check(await flatWhite.getAttribute('data-raster-state') === 'draft', 'hidden items look hidden');
		const cortado = page.locator('[data-raster-item]', { hasText: 'Cortado' }).first();
		await page.mouse.move(2, 2);
		await page.waitForTimeout(500);
		await cortado.hover({ position: { x: 20, y: 20 }, force: true });
		await page.waitForTimeout(300);
		await inShadow(page, h => h.shadowRoot.querySelector('.handle button[aria-label=Delete]').click());
		await page.waitForTimeout(900);
		check(!(await (await fetch(base + '/menu')).text()).includes('Cortado'), 'deleted');
		await page.mouse.move(2, 2);
		await page.keyboard.press('Control+z');
		await page.waitForTimeout(1200);
		check((await (await fetch(base + '/menu')).text()).includes('Cortado'), 'undo brings the item back');

		// keyboard only: Tab reaches items, photos and the new-item card, and
		// every action on an item works from the keyboard (#121)
		await page.goto(base + '/menu');
		await page.waitForTimeout(500);
		await page.mouse.move(2, 2);
		const focused = () => page.evaluate(() => {
			const el = document.activeElement;
			if (el && el.hasAttribute('data-raster-host')) return { kind: 'host' };
			if (!el || !el.matches) return { kind: '' };
			if (el.matches('.raster-ghost')) return { kind: 'ghost' };
			if (el.matches('img[data-raster-field]')) return { kind: 'photo' };
			if (el.matches('[data-raster-item]')) return { kind: 'item', text: el.innerText };
			return { kind: el.tagName };
		});
		const reached = {};
		let photoButton = false, itemHandle = false;
		for (let i = 0; i < 120 && !reached.ghost; i++) {
			await page.keyboard.press('Tab');
			const f = await focused();
			reached[f.kind] = true;
			if (f.kind === 'photo') photoButton = photoButton || await inShadow(page, h => !!h.shadowRoot.querySelector('.photo-button'));
			if (f.kind === 'item') itemHandle = itemHandle || await inShadow(page, h => !!h.shadowRoot.querySelector('.handle button[aria-label=Delete]'));
		}
		check(reached.item && reached.photo && reached.ghost, 'Tab reaches an item, a photo and the new-item card: ' + Object.keys(reached).join(', '));
		check(itemHandle, 'a focused item shows its handle');
		check(photoButton, 'a focused photo shows the replace button');
		// to the first dish, Enter into its handle, Tab to Hide, Enter
		await page.goto(base + '/menu');
		await page.waitForTimeout(500);
		await page.mouse.move(2, 2);
		let first = null;
		for (let i = 0; i < 120; i++) {
			await page.keyboard.press('Tab');
			const f = await focused();
			if (f.kind === 'item') { first = f.text; break; }
		}
		check(first !== null && await page.locator('[data-raster-item]').first().evaluate(el => el === document.activeElement), 'Tab lands on the first item');
		// the hidden dish (Flat white, hidden above; Cortado sorts first)
		const firstItem = page.locator('[data-raster-item]', { hasText: 'Flat white' }).first();
		check(await firstItem.getAttribute('data-raster-state') === 'draft', 'the dish to show is hidden');
		await firstItem.focus();
		await page.keyboard.press('Enter');
		check(await inShadow(page, h => !!h.shadowRoot.activeElement && !!h.shadowRoot.activeElement.closest('.handle')), 'Enter on an item moves into its handle');
		for (let i = 0; i < 8 && !(await inShadow(page, h => h.shadowRoot.activeElement && h.shadowRoot.activeElement.getAttribute('aria-label') === 'Show')); i++) await page.keyboard.press('Tab');
		check(await inShadow(page, h => h.shadowRoot.activeElement && h.shadowRoot.activeElement.getAttribute('aria-label') === 'Show'), 'Tab reaches Show in the handle');
		await page.keyboard.press('Enter');
		await page.waitForTimeout(900);
		check(await firstItem.getAttribute('data-raster-state') === 'live', 'Show, from the keyboard, shows the hidden dish');
		await firstItem.focus();
		await page.keyboard.press('Enter');
		await page.keyboard.press('Escape');
		check(await firstItem.evaluate(el => el === document.activeElement), 'Esc in the handle goes back to the item');
		// a focused photo opens the file chooser with Enter
		const photo = page.locator('img[data-raster-field]').first();
		await photo.focus();
		const [picker] = await Promise.all([page.waitForEvent('filechooser', { timeout: 10000 }).catch(() => null), page.keyboard.press('Enter')]);
		check(!!picker, 'Enter on a photo opens the file chooser: ' + await page.evaluate(() => document.activeElement.outerHTML.slice(0, 200)));
		// the new-item card opens with Enter
		const card = page.locator('.raster-ghost').first();
		await card.focus();
		await page.keyboard.press('Enter');
		check(await card.evaluate(el => el.classList.contains('raster-new') && el.contains(document.activeElement)), 'Enter on the new-item card starts a new item');
		await page.keyboard.press('Escape');

		// a record: the model's actions are buttons, its readonly fields are not
		// editable. Its phone, a link a visitor typed, is never one that runs
		// script, before or after an action answers with the stored value
		const staffView = path.join(root, 'demo/views/cafe/staff.html');
		const staffHtml = fs.readFileSync(staffView, 'utf8');
		process.on('exit', () => fs.writeFileSync(staffView, staffHtml));
		fs.writeFileSync(staffView, staffHtml.split('<tr class="booking"><td><!-- print.name -->').join('<tr class="booking"><td><!-- print.@href.phone --><a class="call" href="#">Call</a><!-- /print.@href.phone --> <!-- print.name -->'));
		await fetch(base + '/visit?date=' + soon, { method: 'POST', body: new URLSearchParams({ raster_form: 'booking.form', raster_ts: stamp(), resource: 'dining-room', name: 'Ilinca', email: 'ilinca@example.com', date: soon, time: '19:00', people: '2', terms: '1' }), redirect: 'manual' });
		// the form's own pattern refuses such a phone; a site's form may not
		execFileSync('php', ['-r', "require 'system/boot.php'; boot::$appname = 'demo'; boot::cli(); foreach (cms_records::find('booking', array('name' => 'Ilinca')) as $b) cms_records::update('booking', $b['id'], array('phone' => 'javascript:alert(1)'));"], { cwd: root, env });
		await page.goto(base + '/staff');
		await page.waitForTimeout(500);
		const booking = page.locator('[data-raster-item]', { hasText: 'Ilinca' }).first();
		check(await booking.locator('td.status').evaluate(el => !el.isContentEditable && !el.querySelector('[contenteditable]')), 'the status is not editable');
		check(await booking.locator('td').first().evaluate(el => el.isContentEditable || !!el.querySelector('[contenteditable]')), 'the name is');
		await page.mouse.move(2, 2);
		await page.waitForTimeout(500);
		await booking.hover({ position: { x: 10, y: 5 }, force: true });
		await page.waitForTimeout(300);
		const buttons = await inShadow(page, h => [...h.shadowRoot.querySelectorAll('.handle button')].map(b => b.textContent || b.getAttribute('aria-label')));
		check(buttons.includes('Confirm booking') && buttons.includes('Decline') && buttons.includes('Cancel booking') && !buttons.includes('Duplicate') && !buttons.includes('Schedule'), 'a booking has its actions, in the café\'s words, instead of duplicate and schedule: ' + buttons.join(', '));
		await inShadow(page, h => [...h.shadowRoot.querySelectorAll('.handle button')].find(b => b.textContent === 'Confirm booking').click());
		await page.waitForTimeout(900);
		check((await booking.locator('td.status').innerText()).trim() === 'confirmed', 'the action\'s result shows in the page');
		check(!/^\s*javascript:/i.test(await booking.locator('a.call').getAttribute('href') || ''), 'the action\'s answer puts no script link back: ' + await booking.locator('a.call').getAttribute('href'));
		fs.writeFileSync(staffView, staffHtml);
		await page.goto(base + '/staff');
		await page.waitForTimeout(500);
		// staff_add: a booking taken over the phone, from the card at the end of
		// each render.cms list of bookings
		const cards = async title => page.locator('h3:has-text("' + title + '") + table .raster-ghost').count();
		check(await cards('Requests') === 1 && await cards('Past') === 1, 'a card for a new booking at the end of each list');
		const phone = page.locator('.raster-ghost').first();
		await phone.click();
		await page.waitForTimeout(200);
		await page.keyboard.type('Phone Pat');
		// a field of the new card selects its example text when it gets focus
		await phone.locator('[data-raster-name=date]').click();
		await page.keyboard.type(wednesday);
		await phone.locator('[data-raster-name=time]').click();
		await page.keyboard.type('21:00');
		await phone.locator('[data-raster-name=resource]').click();
		await page.keyboard.type('dining-room');
		await inShadow(page, h => [...h.shadowRoot.querySelectorAll('.handle button')].find(b => b.textContent === 'Add').click());
		await page.waitForTimeout(900);
		const added = (await (await fetch(base + '/api/cafe/bookings/' + wednesday, { headers: { cookie: (await page.context().cookies()).map(c => c.name + '=' + c.value).join('; ') } })).json());
		check(Array.isArray(added) && added.some(b => b.name === 'Phone Pat' && b.status === 'requested'), 'the phone booking is stored, as a request: ' + JSON.stringify(added));
		check(await page.locator('h3:has-text("Requests") + table .raster-ghost').count() === 1 && await page.locator('h3:has-text("Past") + table .raster-ghost').count() === 1, 'each list keeps its own card after an add');

		// a model's agenda (cms_records::listed): its bookings are edited in place
		await page.goto(base + '/staff');
		await page.waitForTimeout(500);
		const inAgenda = page.locator('tr.agenda-booking[data-raster-item]', { hasText: 'Ilinca' }).first();
		check(await inAgenda.count() === 1, 'the agenda\'s booking is an item the editor knows');
		check(await inAgenda.locator('td.status').evaluate(el => !el.isContentEditable && !el.querySelector('[contenteditable]')), 'its status is not editable');
		// the name's cell: the row starts with the time
		const nameCell = inAgenda.locator('td', { hasText: 'Ilinca' });
		await nameCell.click();
		// the caret at the end of the name
		await nameCell.evaluate(td => {
			const el = td.isContentEditable ? td : td.querySelector('[contenteditable]');
			const range = document.createRange(); range.selectNodeContents(el); range.collapse(false);
			const sel = getSelection(); sel.removeAllRanges(); sel.addRange(range);
		});
		await page.keyboard.type(' Pop');
		await page.mouse.click(5, 5);
		await page.waitForTimeout(1200);
		const day = await (await fetch(base + '/api/cafe/bookings/' + soon, { headers: { cookie: (await page.context().cookies()).map(c => c.name + '=' + c.value).join('; ') } })).json();
		check(day.some(b => b.name === 'Ilinca Pop'), 'an edit in the agenda is saved: ' + JSON.stringify(day.map(b => b.name)));
		check(await page.locator('section.day .raster-ghost').count() === 0, 'the agenda adds no cards: new bookings come from the lists');
		check((await (await fetch(base + '/api/booking/confirm')).status) === 404, 'actions are not reachable over /api');
		await page.screenshot({ path: path.join(screens, 'records.png') });

		// the dock is flicked to the top, stays there, and comes back down with two fingers
		await page.goto(base + '/about');
		await page.waitForTimeout(700);
		const dockBox = () => inShadow(page, h => { const r = h.shadowRoot.querySelector('.dock').getBoundingClientRect(); return { x: r.left + 20, y: r.top + r.height / 2, top: r.top }; });
		const atTop = () => inShadow(page, h => h.shadowRoot.querySelector('.layer').classList.contains('top'));
		let d = await dockBox();
		await page.mouse.move(d.x, d.y);
		await page.mouse.down();
		for (let i = 1; i <= 4; i++) { await page.mouse.move(d.x, d.y - i * 40); await page.waitForTimeout(16); }
		await page.mouse.up();
		await page.waitForTimeout(700);
		check(await atTop() && (await dockBox()).top < 100, 'a flick up sends the dock to the top');
		check(!(await inShadow(page, h => !!h.shadowRoot.querySelector('.panel'))), 'letting go of the dock is not a click');
		await page.screenshot({ path: path.join(screens, 'dock-top.png') });
		await page.reload();
		await page.waitForTimeout(700);
		check(await atTop(), 'the dock stays at the top on the next page');
		d = await dockBox();
		await page.mouse.move(d.x, d.y);
		await page.mouse.wheel(0, -120);
		await page.waitForTimeout(700);
		check(!(await atTop()) && (await dockBox()).top > 600, 'two fingers down on a trackpad bring it back');

		// the page panel
		await page.waitForTimeout(400);
		await inShadow(page, h => h.shadowRoot.querySelector('.dock .pill:not(.primary):not([aria-haspopup])').click());
		await page.waitForTimeout(1000);
		const panel = await inShadow(page, h => h.shadowRoot.querySelector('.panel').innerText);
		check(/Description/i.test(panel) && /not shown in the page/i.test(panel), 'the panel offers fields the page can\'t show');
		await page.screenshot({ path: path.join(screens, 'panel.png') });

		// the format bar: with a mouse it sits by the field; on a phone, where
		// the phone's own Copy and Paste menu covers the words chosen, it sits
		// at the bottom, and a tap on Bold keeps the field and its selection
		const chooseSmall = p => p.evaluate(() => { const t = document.querySelector('.lede p').firstChild; const r = document.createRange(); r.setStart(t, 2); r.setEnd(t, 7); getSelection().removeAllRanges(); getSelection().addRange(r); });
		const boldButton = p => host(p).evaluate(h => { const b = h.shadowRoot.querySelector('.format button[aria-label=Bold]'); const r = b.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2, docked: b.parentNode.classList.contains('docked') }; });
		const startEditing = async p => { if (!(await p.evaluate(() => document.documentElement.classList.contains('raster-editing')))) await host(p).evaluate(h => h.shadowRoot.querySelector('.dock .pill.primary').click()); await p.waitForTimeout(300); };
		await page.goto(base + '/');
		await page.waitForTimeout(400);
		await startEditing(page);
		await page.locator('.lede').click();
		await chooseSmall(page);
		let bold = await boldButton(page);
		check(!bold.docked && bold.y < (await page.locator('.lede').boundingBox()).y, 'with a mouse the format bar is above the field');
		await page.mouse.click(bold.x, bold.y);
		check(/<b>small<\/b>/.test(await page.locator('.lede').innerHTML()), 'Bold, clicked, makes the words chosen bold');
		await page.keyboard.press('Escape');
		const formatContext = await browser.newContext({ viewport: { width: 390, height: 664 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, storageState: await context.storageState() });
		const phoneTab = await formatContext.newPage();
		phoneTab.on('pageerror', e => errors.push(e.message));
		await phoneTab.goto(base + '/');
		await phoneTab.waitForTimeout(500);
		await startEditing(phoneTab);
		await phoneTab.locator('.lede').tap();
		await chooseSmall(phoneTab);
		await phoneTab.waitForTimeout(300);
		bold = await boldButton(phoneTab);
		check(bold.docked && bold.y > 664 - 160, 'on a phone the format bar sits at the bottom: ' + JSON.stringify(bold));
		await phoneTab.touchscreen.tap(bold.x, bold.y);
		await phoneTab.waitForTimeout(200);
		check(/<b>small<\/b>/.test(await phoneTab.locator('.lede').innerHTML()), 'Bold, tapped, makes the words chosen bold');
		check(await phoneTab.evaluate(() => document.activeElement.classList.contains('lede') && String(getSelection()) === 'small'), 'after the tap the field keeps its focus and the words chosen');
		await phoneTab.screenshot({ path: path.join(screens, 'format-phone.png') });
		await formatContext.close();
		// on a phone: the link box is 16px, so iOS Safari doesn't zoom in on
		// it, and the dock fits (no language badge, no save status)
		const phoneContext = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, storageState: await context.storageState() });
		const mobile = await phoneContext.newPage();
		await mobile.goto(base + '/');
		await mobile.waitForTimeout(500);
		await mobile.evaluate(() => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'e', bubbles: true })));
		await mobile.waitForTimeout(400);
		await mobile.locator('.lede[data-raster-field], .lede [data-raster-field]').first().tap();
		await mobile.waitForTimeout(300);
		await mobile.locator('[data-raster-host]').evaluate(h => { const b = h.shadowRoot.querySelector('.format button[aria-label=Link]'); b.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true })); });
		await mobile.waitForTimeout(300);
		const linkSize = await mobile.locator('[data-raster-host]').evaluate(h => { const i = h.shadowRoot.querySelector('.format input'); return i ? parseFloat(getComputedStyle(i).fontSize) : 0; });
		check(linkSize >= 16, 'on a phone the link box is at least 16px, so Safari doesn\'t zoom: ' + linkSize);
		const fits = await mobile.locator('[data-raster-host]').evaluate(h => {
			const shown = [...h.shadowRoot.querySelectorAll('.dock > *')].filter(el => getComputedStyle(el).display !== 'none');
			return { who: shown.some(el => el.classList.contains('who')), inView: shown.every(el => { const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= 390; }) };
		});
		check(fits.who && fits.inView, 'on a phone the dock keeps its avatar and every button is in view: ' + JSON.stringify(fits));
		check(await mobile.locator('[data-raster-host]').evaluate(h => !h.shadowRoot.querySelector('.dock .status')), 'no "All saved" in the dock: each save shows its own tick');
		await phoneContext.close();
		check(errors.length === 0, 'no script errors: ' + errors.join('; '));
		}
		if (!process.env.RASTER_TEST_ONLY || process.env.RASTER_TEST_ONLY === 'multilingual') await multilingual(browser);
		if (!process.env.RASTER_TEST_ONLY || process.env.RASTER_TEST_ONLY === 'labels-fit') await labelsFit(browser);
	} finally {
		await browser.close();
		server.kill();
		fs.rmSync(tmp, { recursive: true, force: true });
	}
	console.log(`\n${passed} passed, ${failures} failed`);
	process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
