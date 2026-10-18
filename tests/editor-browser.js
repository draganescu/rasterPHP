// The in-page editor in a real browser: node tests/editor-browser.js
//
// Optional (it needs Playwright: npm i playwright, and a Chromium it can
// find; set CHROMIUM=/path/to/chrome if needed). Starts the demo café on a
// fresh database, logs in as an editor and uses the editor the way a person
// would: edit mode, typing, undo, adding an item with a photo, hiding,
// deleting and undoing, a keyboard-only pass, Enter in a paragraph field,
// the page panel. Screenshots go to tests/screens/.
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const net = require('net');

const root = path.resolve(__dirname, '..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'raster-editor-'));
const screens = path.join(__dirname, 'screens');
fs.mkdirSync(screens, { recursive: true });
// an evening a week from now, so the agenda (the coming evenings) shows it
const soon = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
const later = new Date(Date.now() + 60 * 86400000).toISOString().slice(0, 10);
const env = Object.assign({}, process.env, { RASTER_APP: 'demo', RASTER_DB: path.join(tmp, 'cafe.sqlite'), RASTER_MAIL: 'log://' + path.join(tmp, 'mail') });

let failures = 0, passed = 0;
function check(condition, message) { if (condition) passed++; else { failures++; console.log('  ✗ ' + message); } }
function port() { return new Promise(r => { const s = net.createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); }); }
const host = page => page.locator('[data-raster-host]');
const inShadow = (page, fn, arg) => host(page).evaluate(fn, arg);

(async () => {
	const p = await port();
	const base = 'http://127.0.0.1:' + p;
	execFileSync('php', [path.join(root, 'bin/raster'), 'user', 'staff@cafe.test', '--role=editor', '--password=staff password', '--name=Sam'], { env });
	const server = spawn('php', ['-S', '127.0.0.1:' + p, path.join(root, 'index.php')], { cwd: root, env, stdio: 'ignore' });
	await new Promise(r => setTimeout(r, 600));
	for (const url of ['/', '/menu', '/about']) await fetch(base + url);
	const browser = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {});
	const errors = [];
	try {
		const context = await browser.newContext({ viewport: { width: 1280, height: 860 } });
		const page = await context.newPage();
		page.on('pageerror', e => errors.push(e.message));
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
		fs.writeFileSync(about, aboutView.replace('<h2>Opening hours</h2>', '<p class="lead"><!-- print.cms.lead -->We roast <em>our own</em> beans.<!-- /print.cms.lead --></p><ul class="features"><!-- print.cms.features --><li>Fast</li><li>Cheap</li><!-- /print.cms.features --></ul><h2>Opening hours</h2>'));
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
		const firstItem = page.locator('[data-raster-item]').first();
		check(first !== null && await firstItem.evaluate(el => el === document.activeElement), 'Tab lands on the first item');
		await page.keyboard.press('Enter');
		check(await inShadow(page, h => !!h.shadowRoot.activeElement && !!h.shadowRoot.activeElement.closest('.handle')), 'Enter on an item moves into its handle');
		for (let i = 0; i < 8 && !(await inShadow(page, h => h.shadowRoot.activeElement && h.shadowRoot.activeElement.getAttribute('aria-label') === 'Show')); i++) await page.keyboard.press('Tab');
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

		// a record: the model's actions are buttons, its readonly fields are not editable
		await fetch(base + '/visit', { method: 'POST', body: new URLSearchParams({ raster_form: 'reservation.book', name: 'Ilinca', email: 'ilinca@example.com', date: soon, guests: '2', terms: '1' }), redirect: 'manual' });
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
		check(buttons.includes('Confirm') && buttons.includes('Cancel') && !buttons.includes('Duplicate') && !buttons.includes('Schedule'), 'a booking has its actions instead of duplicate and schedule: ' + buttons.join(', '));
		await inShadow(page, h => [...h.shadowRoot.querySelectorAll('.handle button')].find(b => b.textContent === 'Confirm').click());
		await page.waitForTimeout(900);
		check((await booking.locator('td.status').innerText()).trim() === 'confirmed', 'the action\'s result shows in the page');
		// staff_add: a booking taken over the phone, from the card at the end of
		// each render.cms list of bookings
		const cards = async title => page.locator('h3:has-text("' + title + '") + table .raster-ghost').count();
		check(await cards('To confirm') === 1 && await cards('Past evenings') === 1, 'a card for a new booking at the end of each list');
		const phone = page.locator('.raster-ghost').first();
		await phone.click();
		await page.waitForTimeout(200);
		await page.keyboard.type('Phone Pat');
		// a field of the new card selects its example text when it gets focus
		await phone.locator('[data-raster-name=date]').click();
		await page.keyboard.type(later);
		await inShadow(page, h => [...h.shadowRoot.querySelectorAll('.handle button')].find(b => b.textContent === 'Add').click());
		await page.waitForTimeout(900);
		const added = (await (await fetch(base + '/api/reservation/day/' + later, { headers: { cookie: (await page.context().cookies()).map(c => c.name + '=' + c.value).join('; ') } })).json());
		check(Array.isArray(added) && added.some(b => b.name === 'Phone Pat' && b.status === 'new'), 'the phone booking is stored, as new: ' + JSON.stringify(added));
		check(await page.locator('h3:has-text("To confirm") + table .raster-ghost').count() === 1 && await page.locator('h3:has-text("Past evenings") + table .raster-ghost').count() === 1, 'each list keeps its own card after an add');

		// a model's agenda (cms_records::listed): its bookings are edited in place
		await page.goto(base + '/staff');
		await page.waitForTimeout(500);
		const inAgenda = page.locator('tr.agenda-booking[data-raster-item]', { hasText: 'Ilinca' }).first();
		check(await inAgenda.count() === 1, 'the agenda\'s booking is an item the editor knows');
		check(await inAgenda.locator('td.status').evaluate(el => !el.isContentEditable && !el.querySelector('[contenteditable]')), 'its status is not editable');
		await inAgenda.locator('td').first().click();
		// the caret at the end of the name
		await inAgenda.locator('td').first().evaluate(td => {
			const el = td.isContentEditable ? td : td.querySelector('[contenteditable]');
			const range = document.createRange(); range.selectNodeContents(el); range.collapse(false);
			const sel = getSelection(); sel.removeAllRanges(); sel.addRange(range);
		});
		await page.keyboard.type(' Pop');
		await page.mouse.click(5, 5);
		await page.waitForTimeout(1200);
		const day = await (await fetch(base + '/api/reservation/day/' + soon, { headers: { cookie: (await page.context().cookies()).map(c => c.name + '=' + c.value).join('; ') } })).json();
		check(day.some(b => b.name === 'Ilinca Pop'), 'an edit in the agenda is saved: ' + JSON.stringify(day.map(b => b.name)));
		check(await page.locator('section.evening .raster-ghost').count() === 0, 'the agenda adds no cards: new bookings come from the lists');
		check((await (await fetch(base + '/api/reservation/confirm')).status) === 404, 'actions are not reachable over /api');
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
		check(errors.length === 0, 'no script errors: ' + errors.join('; '));
	} finally {
		await browser.close();
		server.kill();
		fs.rmSync(tmp, { recursive: true, force: true });
	}
	console.log(`\n${passed} passed, ${failures} failed`);
	process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
