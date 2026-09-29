import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const BASE = 'http://managehosting.local';
const EMAIL = process.env.PROXMOX_E2E_EMAIL;
const PASSWORD = process.env.PROXMOX_E2E_PASSWORD;
const OUT = process.env.PROXMOX_E2E_OUT;
const HOSTING_ID = 22;
const HOSTING_URL = `${BASE}/admin/hosting/${HOSTING_ID}`;
const EDIT_URL = `${HOSTING_URL}/edit?tab=lifecycle`;

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

// Reads the card badge only — never reloads, so in-flight action fetches are not aborted.
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

async function readFlash(page) {
  const alerts = await page.locator('.alert-success, .alert-danger').allTextContents().catch(() => []);
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

  // ── baseline state ───────────────────────────────────────────────────────
  await page.goto(HOSTING_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#compute-panel-proxmox');
  const baseline = (await page.locator('#compute-state-proxmox').innerText()).trim();
  step('resume.baseline', { state: baseline });
  await shot(page, '16-resume-baseline');

  // ── start ────────────────────────────────────────────────────────────────
  await page.locator('#compute-panel-proxmox [data-compute-action="start"]').click();
  const started = await waitForComputeState(page, 'Running', 6 * 60 * 1000);
  step('power.start', { state: started });
  await shot(page, '17-vm-running');

  // ── restart (typed confirmation) ─────────────────────────────────────────
  await page.locator('#compute-panel-proxmox [data-compute-action="restart"]').click();
  const restartForm = page.locator('#compute-panel-proxmox [data-compute-view="restart"]');
  await restartForm.waitFor({ state: 'visible' });
  const hostName = await restartForm.getAttribute('data-compute-confirm-expected');
  await restartForm.locator('[data-compute-confirm-input]').fill(hostName);
  await restartForm.locator('[data-compute-submit]').click();
  const restarted = await waitForComputeState(page, 'Running', 6 * 60 * 1000);
  step('power.restart', { state: restarted, hostName });
  await shot(page, '18-vm-restarted');

  // ── suspend (service lifecycle form) ─────────────────────────────────────
  await page.goto(EDIT_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#lifecycle textarea[name="reason"]');
  await page.fill('#lifecycle textarea[name="reason"]', 'E2E lifecycle test — suspend');
  await page.click('#lifecycle button:has-text("Suspend")');
  await page.waitForSelector('.alert-success, .alert-danger', { timeout: 240000 });
  const suspendFlash = await readFlash(page);
  step('service.suspend', { flash: suspendFlash });
  await shot(page, '19-service-suspended');

  await page.goto(HOSTING_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#compute-state-proxmox');
  const stateSuspended = await waitForComputeState(page, 'Off', 6 * 60 * 1000);
  step('service.suspend.vm-state', { state: stateSuspended });
  await shot(page, '20-vm-off-after-suspend');

  // ── unsuspend ────────────────────────────────────────────────────────────
  await page.goto(EDIT_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#lifecycle');
  await page.click('#lifecycle button:has-text("Reactivate")');
  await page.waitForSelector('.alert-success, .alert-danger', { timeout: 240000 });
  const unsuspendFlash = await readFlash(page);
  step('service.unsuspend', { flash: unsuspendFlash });
  await shot(page, '21-service-unsuspended');

  await page.goto(HOSTING_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#compute-state-proxmox');
  const stateResumed = await waitForComputeState(page, 'Running', 6 * 60 * 1000);
  step('service.unsuspend.vm-state', { state: stateResumed });

  // ── delete VM (destroy on PVE + terminate) ───────────────────────────────
  await page.locator('#compute-panel-proxmox [data-compute-action="delete"]').click();
  const deleteForm = page.locator('#compute-panel-proxmox [data-compute-view="delete"]');
  await deleteForm.waitFor({ state: 'visible' });
  await deleteForm.locator('[data-compute-confirm-input]').fill(hostName);
  await shot(page, '22-delete-confirm');
  await deleteForm.locator('[data-compute-submit]').click();
  const deleted = await waitForComputeState(page, 'Not created', 6 * 60 * 1000);
  step('vm.deleted', { state: deleted });
  await shot(page, '23-vm-deleted');

  await page.goto(HOSTING_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('body');
  const body = await page.locator('body').innerText();
  const terminated = body.includes('terminated') || body.includes('Terminated');
  step('hosting.after-delete', { terminated });
  await shot(page, '24-hosting-terminated');
} catch (err) {
  step('FAILED', { error: String(err && err.message ? err.message : err).slice(0, 500) });
  await shot(page, 'ZZ-resume-failure');
  process.exitCode = 1;
} finally {
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'resume-log.json'), JSON.stringify(steps, null, 2));
}
