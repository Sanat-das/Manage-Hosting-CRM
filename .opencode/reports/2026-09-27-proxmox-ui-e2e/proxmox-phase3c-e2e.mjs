import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const BASE = 'http://managehosting.local';
const EMAIL = process.env.PROXMOX_E2E_EMAIL;
const PASSWORD = process.env.PROXMOX_E2E_PASSWORD;
const OUT = process.env.PROXMOX_E2E_OUT;
const HOSTING_URL = `${BASE}/admin/hosting/22`;

const steps = [];
const t0 = Date.now();
const stamp = () => `+${Math.round((Date.now() - t0) / 1000)}s`;
function step(name, data = {}) {
  steps.push({ at: stamp(), step: name, ...data });
  console.log(JSON.stringify(steps[steps.length - 1]));
}
async function shot(page, name) {
  await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true }).catch(() => {});
}
async function waitForComputeState(page, target, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  let last = '';
  while (Date.now() < deadline) {
    try {
      const el = page.locator('#compute-state-proxmox');
      if (await el.count()) {
        last = ((await el.first().innerText()) || '').trim();
        if (last === target) return last;
      }
    } catch { /* mid-navigation */ }
    await page.waitForTimeout(2500);
  }
  throw new Error(`Compute state did not reach "${target}" within ${timeoutMs / 1000}s (last="${last}")`);
}
async function freshState(page, target, timeoutMs = 60000) {
  await page.waitForTimeout(11000);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#compute-state-proxmox');
  return waitForComputeState(page, target, timeoutMs);
}
async function waitActionDone(page) {
  await page.waitForFunction(() => {
    const p = document.querySelector('#compute-panel-proxmox [data-compute-progress]');
    return !p || !p.classList.contains('is-open');
  }, null, { timeout: 6 * 60 * 1000 }).catch(() => { /* page reloaded — completion too */ });
}
async function readCardFeedback(page) {
  const alerts = await page.locator('#compute-panel-proxmox [data-compute-feedback]').allTextContents().catch(() => []);
  return alerts.map((a) => a.replace(/\s+/g, ' ').trim()).filter(Boolean);
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1680, height: 1000 } });
const page = await context.newPage();
page.setDefaultTimeout(20000);

try {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([
    page.waitForURL('**/admin/dashboard', { timeout: 30000 }),
    page.click('button[type="submit"]'),
  ]);
  step('login.ok');

  await page.goto(HOSTING_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#compute-panel-proxmox');
  await page.waitForTimeout(11000);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#compute-state-proxmox');
  let baseline = (await page.locator('#compute-state-proxmox').innerText()).trim();
  if (baseline !== 'Off') {
    // Delete is gated on a stopped VM (PVE refuses to destroy a running one).
    await page.locator('#compute-panel-proxmox [data-compute-action="stop"]').click();
    await waitActionDone(page);
    baseline = await freshState(page, 'Off');
  }
  step('phase3c.baseline', { state: baseline });

  // ── delete VM (destroy + terminate) ──────────────────────────────────────
  await page.locator('#compute-panel-proxmox [data-compute-action="delete"]').click();
  const deleteForm = page.locator('#compute-panel-proxmox [data-compute-view="delete"]');
  await deleteForm.waitFor({ state: 'visible' });
  const hostName = await deleteForm.getAttribute('data-compute-confirm-expected');
  await deleteForm.locator('[data-compute-confirm-input]').fill(hostName);
  await shot(page, '31-delete-confirm');
  await deleteForm.locator('[data-compute-submit]').click();
  await waitActionDone(page);
  step('vm.deleted', {
    state: await freshState(page, 'Not created'),
    feedback: await readCardFeedback(page),
  });
  await shot(page, '32-vm-deleted');

  await page.goto(HOSTING_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('body');
  const body = await page.locator('body').innerText();
  step('hosting.after-delete', { terminated: /terminated/i.test(body), hostName });
  await shot(page, '33-hosting-terminated');
} catch (err) {
  step('FAILED', { error: String(err && err.message ? err.message : err).slice(0, 500) });
  await shot(page, 'ZZ-phase3c-failure');
  process.exitCode = 1;
} finally {
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'phase3c-log.json'), JSON.stringify(steps, null, 2));
}
