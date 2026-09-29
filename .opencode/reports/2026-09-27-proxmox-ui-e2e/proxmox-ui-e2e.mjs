import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const BASE = 'http://managehosting.local';
const EMAIL = process.env.PROXMOX_E2E_EMAIL;
const PASSWORD = process.env.PROXMOX_E2E_PASSWORD;
const OUT = process.env.PROXMOX_E2E_OUT;
const PRODUCT_LABEL = 'Linux VPS';
const CUSTOMER_ID = '1';

const steps = [];
const evidence = {};
const t0 = Date.now();
const stamp = () => `+${Math.round((Date.now() - t0) / 1000)}s`;

function step(name, data = {}) {
  const entry = { at: stamp(), step: name, ...data };
  steps.push(entry);
  console.log(JSON.stringify(entry));
}

async function shot(page, name) {
  const file = path.join(OUT, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true }).catch(() => {});
  return file;
}

async function waitForComputeState(page, target, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  let last = '';
  let lastReload = Date.now();
  while (Date.now() < deadline) {
    try {
      const el = page.locator('#compute-state-proxmox');
      if (await el.count()) {
        last = ((await el.first().innerText()) || '').trim();
        if (last === target) return last;
      }
    } catch { /* mid-navigation */ }
    await page.waitForTimeout(3000);
    if (Date.now() - lastReload > 30000) {
      await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => {});
      lastReload = Date.now();
    }
  }
  throw new Error(`Compute state did not reach "${target}" within ${timeoutMs / 1000}s (last="${last}")`);
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1680, height: 1000 } });
const page = await context.newPage();
page.setDefaultTimeout(20000);
page.on('pageerror', (e) => step('pageerror', { text: String(e).slice(0, 300) }));
page.on('console', (m) => { if (m.type() === 'error') step('console.error', { text: m.text().slice(0, 200) }); });

try {
  // ── 1. login ─────────────────────────────────────────────────────────────
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([
    page.waitForURL('**/admin/dashboard', { timeout: 30000 }),
    page.click('button[type="submit"]'),
  ]);
  step('login.ok', { url: page.url() });
  await shot(page, '01-dashboard');

  // ── 2. create order ──────────────────────────────────────────────────────
  await page.goto(`${BASE}/admin/orders/create?customer_id=${CUSTOMER_ID}`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#line-product-0');
  await page.selectOption('#customer_id', CUSTOMER_ID);
  await page.selectOption('#line-product-0', { label: PRODUCT_LABEL });
  await page.waitForFunction(() => {
    const p = document.querySelector('#line-price-0');
    return p && p.value && p.value !== '0.00' && p.value !== '0';
  }, { timeout: 15000 });
  const unitPrice = await page.inputValue('#line-price-0');
  const cycle = await page.inputValue('.line-cycle, select[name="lines[0][billing_cycle]"]').catch(() => '');
  evidence.unitPrice = unitPrice;
  step('order.form.ready', { unitPrice, cycle });
  await shot(page, '02-order-form');

  await page.click('button[type="submit"]:has-text("Create Order")');
  await page.waitForURL(/\/admin\/orders\/\d+/, { timeout: 30000 });
  await page.waitForLoadState('domcontentloaded');
  const orderUrl = page.url();
  const orderId = Number(orderUrl.match(/\/admin\/orders\/(\d+)/)[1]);
  const orderNo = (await page.locator('h4').first().innerText()).trim();
  const statusPending = (await page.locator('.badge').first().innerText()).trim();
  evidence.orderId = orderId;
  evidence.orderNo = orderNo;
  step('order.created', { orderId, orderNo, status: statusPending, url: orderUrl });
  await shot(page, '03-order-pending');

  // ── 3. activate order ────────────────────────────────────────────────────
  const activateBtn = page.locator('button[data-bs-target="#order-status-active"]');
  await activateBtn.first().waitFor({ state: 'visible', timeout: 10000 });
  await activateBtn.first().click();
  const confirmModal = page.locator('#order-status-active');
  await confirmModal.waitFor({ state: 'visible' });
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    confirmModal.locator('button[type="submit"]').click(),
  ]);
  await page.waitForTimeout(1500);
  const bodyAfterActivate = await page.locator('body').innerText();
  const activeFlash = bodyAfterActivate.includes('is now active') ? 'is now active' : '(no flash matched)';
  const hostingHref = await page.locator('a[href*="/admin/hosting/"]').first().getAttribute('href').catch(() => null);
  evidence.hostingHref = hostingHref;
  step('order.activated', { flash: activeFlash, hostingHref, url: page.url() });
  await shot(page, '04-order-active');

  if (!hostingHref) throw new Error('No hosting account link on the order page after activation.');
  const hostingUrl = new URL(hostingHref, BASE).toString();
  evidence.hostingUrl = hostingUrl;

  // ── 4. hosting page: compute card pre-create ─────────────────────────────
  await page.goto(hostingUrl, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#compute-panel-proxmox');
  const hostName = (await page.locator('h4, h1').first().innerText()).trim();
  const stateBefore = (await page.locator('#compute-state-proxmox').innerText()).trim();
  const modeBadge = (await page.locator('#compute-panel-proxmox .badge').first().innerText()).trim();
  evidence.hostingId = Number(hostingUrl.match(/\/admin\/hosting\/(\d+)/)[1]);
  evidence.hostName = hostName;
  step('hosting.compute.before', { hostingId: evidence.hostingId, hostName, state: stateBefore, mode: modeBadge });
  await shot(page, '05-hosting-before');

  // ── 5. create VM (manual provisioning, template picker) ──────────────────
  const panel = page.locator('#compute-panel-proxmox');
  await panel.locator('[data-compute-action="create"]').click();
  const createForm = panel.locator('[data-compute-view="create"]');
  await createForm.waitFor({ state: 'visible' });
  const templateOptions = await createForm.locator('select option').allTextContents();
  const templateValue = await createForm.locator('select').inputValue();
  step('hosting.create.form', { templateValue, templateOptions: templateOptions.map((t) => t.trim()) });
  await shot(page, '06-create-vm-form');
  await createForm.locator('[data-compute-submit]').click();

  await page.waitForTimeout(2000);
  step('hosting.create.submitted', { state: (await page.locator('#compute-state-proxmox').innerText().catch(() => '')).trim() });

  const runningState = await waitForComputeState(page, 'Running', 20 * 60 * 1000);
  step('hosting.vm.running', { state: runningState });
  await shot(page, '07-vm-running');

  // ── 6. cross-check on the server page (VMID + live state) ────────────────
  await page.goto(`${BASE}/admin/servers/6`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('table');
  const serverText = await page.locator('body').innerText();
  const vmRow = serverText.split('\n').findIndex((l) => l.includes(hostName));
  const serverRow = vmRow >= 0 ? serverText.split('\n').slice(vmRow, vmRow + 8).join(' | ') : '(host_name not found in server VM table)';
  const vmidMatch = serverRow.match(/\b(\d{3,4})\b/);
  evidence.vmid = vmidMatch ? vmidMatch[1] : null;
  step('server.vm-row', { row: serverRow, vmid: evidence.vmid });
  await shot(page, '08-server-vm-row');

  // ── 7. power actions on the compute card ─────────────────────────────────
  await page.goto(hostingUrl, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#compute-panel-proxmox');

  // stop
  await page.locator('#compute-panel-proxmox [data-compute-action="stop"]').click({ timeout: 10000 });
  await waitForComputeState(page, 'Off', 5 * 60 * 1000);
  step('power.stop', { state: 'Off' });
  await shot(page, '09-vm-stopped');

  // start
  await page.locator('#compute-panel-proxmox [data-compute-action="start"]').click({ timeout: 10000 });
  await waitForComputeState(page, 'Running', 5 * 60 * 1000);
  step('power.start', { state: 'Running' });

  // restart (typed confirmation)
  await page.locator('#compute-panel-proxmox [data-compute-action="restart"]').click();
  const restartForm = page.locator('#compute-panel-proxmox [data-compute-view="restart"]');
  await restartForm.waitFor({ state: 'visible' });
  await restartForm.locator('[data-compute-confirm-input]').fill(hostName);
  await restartForm.locator('[data-compute-submit]').click();
  await page.waitForTimeout(3000);
  await waitForComputeState(page, 'Running', 5 * 60 * 1000);
  step('power.restart', { state: 'Running' });
  await shot(page, '10-vm-restarted');

  // ── 8. suspend / unsuspend the service (hosting lifecycle tab) ───────────
  await page.goto(`${BASE}/admin/hosting/${evidence.hostingId}/edit?tab=lifecycle`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#lifecycle');
  await page.fill('#lifecycle textarea[name="reason"]', 'E2E lifecycle test — suspend');
  await page.click('#lifecycle button:has-text("Suspend")');
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(1500);
  const suspendText = await page.locator('body').innerText();
  evidence.suspendFlash = suspendText.includes('suspended (module synced)') ? 'suspended (module synced)' : suspendText.match(/suspended[^\n]*/)?.[0] ?? '(no flash)';
  step('service.suspend', { flash: evidence.suspendFlash });
  await shot(page, '11-service-suspended');

  await page.goto(hostingUrl, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#compute-state-proxmox');
  const stateSuspended = await waitForComputeState(page, 'Off', 5 * 60 * 1000);
  step('service.suspend.vm-state', { state: stateSuspended });

  // unsuspend
  await page.goto(`${BASE}/admin/hosting/${evidence.hostingId}/edit?tab=lifecycle`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#lifecycle');
  await page.click('#lifecycle button:has-text("Reactivate")');
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(1500);
  const unsuspendText = await page.locator('body').innerText();
  evidence.unsuspendFlash = unsuspendText.includes('unsuspended (module synced)') ? 'unsuspended (module synced)' : unsuspendText.match(/unsuspended[^\n]*/)?.[0] ?? '(no flash)';
  step('service.unsuspend', { flash: evidence.unsuspendFlash });
  await shot(page, '12-service-unsuspended');

  await page.goto(hostingUrl, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#compute-state-proxmox');
  const stateResumed = await waitForComputeState(page, 'Running', 5 * 60 * 1000);
  step('service.unsuspend.vm-state', { state: stateResumed });

  // ── 9. delete VM (module delete = destroy on PVE + terminate) ────────────
  await page.locator('#compute-panel-proxmox [data-compute-action="delete"]').click();
  const deleteForm = page.locator('#compute-panel-proxmox [data-compute-view="delete"]');
  await deleteForm.waitFor({ state: 'visible' });
  await shot(page, '13-delete-confirm');
  await deleteForm.locator('[data-compute-confirm-input]').fill(hostName);
  await deleteForm.locator('[data-compute-submit]').click();

  const deletedState = await waitForComputeState(page, 'Not created', 5 * 60 * 1000);
  step('vm.deleted', { state: deletedState });
  await shot(page, '14-vm-deleted');

  const hostingAfter = await page.locator('body').innerText();
  evidence.hostingStatusAfterDelete = hostingAfter.match(/Terminated|terminated/)?.[0] ?? null;
  step('hosting.after-delete', { statusHint: evidence.hostingStatusAfterDelete, url: page.url() });
} catch (err) {
  step('FAILED', { error: String(err && err.message ? err.message : err).slice(0, 500) });
  await shot(page, 'ZZ-failure');
  process.exitCode = 1;
} finally {
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'run-log.json'), JSON.stringify({ evidence, steps }, null, 2));
}
