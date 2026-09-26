// The in-page editor in a real browser: node tests/editor-browser.js
//
// Optional (it needs Playwright: npm i playwright, and a Chromium it can
// find; set CHROMIUM=/path/to/chrome if needed). Starts the demo café on a
// fresh database, logs in as an editor and uses the editor the way a person
// would: edit mode, typing, undo, adding an item with a photo, hiding,
// deleting and undoing, the page panel. Screenshots go to tests/screens/.
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

		// the page panel
		await page.goto(base + '/about');
		await page.waitForTimeout(400);
		await inShadow(page, h => h.shadowRoot.querySelector('.dock .pill:not(.primary)').click());
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
