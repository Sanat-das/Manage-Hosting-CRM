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
async function readCardFeedback(page) {
  const alerts = await page.locator('#compute-panel-proxmox [data-compute-feedback]').allTextContents().catch(() => []);
  return alerts.map((a) => a.replace(/\s+/g, ' ').trim()).filter(Boolean);
}

/**
 * Click a direct card action, wait for the action fetch to finish, then let
 * the presenter's 10s VM-state cache expire before re-rendering — the card
 * reloads immediately after the action, so without the wait the fresh page
 * can still render the pre-action state.
 */
async function runAction(page, action, expect, timeoutMs = 6 * 60 * 1000) {
  await page.locator(`#compute-panel-proxmox [data-compute-action="${action}"]`).click();
  await page.waitForFunction(() => {
    const p = document.querySelector('#compute-panel-proxmox [data-compute-progress]');
    return !p || !p.classList.contains('is-open');
  }, null, { timeout: timeoutMs }).catch(() => { /* page reloaded — that is completion too */ });
  await page.waitForTimeout(11000);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#compute-state-proxmox');
  return waitForComputeState(page, expect, 60000);
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
  const baseline = (await page.locator('#compute-state-proxmox').innerText()).trim();
  step('phase2.baseline', { state: baseline });
  await shot(page, '25-phase2-baseline');

  if (baseline === 'Off') {
    step('power.start-before-restart', { state: await runAction(page, 'start', 'Running') });
  }

  // ── restart (fixed reboot path) ──────────────────────────────────────────
  await page.locator('#compute-panel-proxmox [data-compute-action="restart"]').click();
  const restartForm = page.locator('#compute-panel-proxmox [data-compute-view="restart"]');
  await restartForm.waitFor({ state: 'visible' });
  const hostName = await restartForm.getAttribute('data-compute-confirm-expected');
  await restartForm.locator('[data-compute-confirm-input]').fill(hostName);
  await restartForm.locator('[data-compute-submit]').click();
  await page.waitForFunction(() => {
    const p = document.querySelector('#compute-panel-proxmox [data-compute-progress]');
    return !p || !p.classList.contains('is-open');
  }, null, { timeout: 6 * 60 * 1000 }).catch(() => {});
  await page.waitForTimeout(11000);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#compute-state-proxmox');
  step('power.restart', {
    state: await waitForComputeState(page, 'Running', 60000),
    hostName,
    feedback: await readCardFeedback(page),
  });
  await shot(page, '26-restarted');

  // ── stop then start (fixed parameter-less start path) ────────────────────
  step('power.stop', { state: await runAction(page, 'stop', 'Off') });
  step('power.start', { state: await runAction(page, 'start', 'Running') });
  await shot(page, '27-started-after-fix');

  // ── suspend / unsuspend (service lifecycle) ──────────────────────────────
  await page.goto(EDIT_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#lifecycle textarea[name="reason"]');
  await page.fill('#lifecycle textarea[name="reason"]', 'E2E lifecycle test — suspend');
  await page.click('#lifecycle button:has-text("Suspend")');
  await page.waitForSelector('.alert-success, .alert-danger', { timeout: 240000 });
  step('service.suspend', { flash: await readFlash(page) });
  await shot(page, '28-service-suspended');

  await page.goto(HOSTING_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#compute-state-proxmox');
  step('service.suspend.vm-state', { state: await waitForComputeState(page, 'Off', 6 * 60 * 1000) });

  await page.goto(EDIT_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#lifecycle');
  await page.click('#lifecycle button:has-text("Reactivate")');
  await page.waitForSelector('.alert-success, .alert-danger', { timeout: 240000 });
  step('service.unsuspend', { flash: await readFlash(page) });
  await shot(page, '29-service-unsuspended');

  await page.goto(HOSTING_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#compute-state-proxmox');
  step('service.unsuspend.vm-state', { state: await waitForComputeState(page, 'Running', 6 * 60 * 1000) });
  await shot(page, '30-unsuspended-running');

  // ── delete VM (destroy + terminate) ──────────────────────────────────────
  await page.locator('#compute-panel-proxmox [data-compute-action="delete"]').click();
  const deleteForm = page.locator('#compute-panel-proxmox [data-compute-view="delete"]');
  await deleteForm.waitFor({ state: 'visible' });
  await deleteForm.locator('[data-compute-confirm-input]').fill(hostName);
  await shot(page, '31-delete-confirm');
  await deleteForm.locator('[data-compute-submit]').click();
  await page.waitForFunction(() => {
    const p = document.querySelector('#compute-panel-proxmox [data-compute-progress]');
    return !p || !p.classList.contains('is-open');
  }, null, { timeout: 6 * 60 * 1000 }).catch(() => {});
  await page.waitForTimeout(11000);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#compute-state-proxmox');
  step('vm.deleted', {
    state: await waitForComputeState(page, 'Not created', 60000),
    feedback: await readCardFeedback(page),
  });
  await shot(page, '32-vm-deleted');

  await page.goto(HOSTING_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('body');
  const body = await page.locator('body').innerText();
  step('hosting.after-delete', { terminated: /terminated/i.test(body) });
  await shot(page, '33-hosting-terminated');
} catch (err) {
  step('FAILED', { error: String(err && err.message ? err.message : err).slice(0, 500) });
  await shot(page, 'ZZ-phase2-failure');
  process.exitCode = 1;
} finally {
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'phase2-log.json'), JSON.stringify(steps, null, 2));
}
