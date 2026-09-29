import { chromium } from 'playwright';

const BASE = 'http://managehosting.local';
const EMAIL = process.env.PROXMOX_E2E_EMAIL;
const PASSWORD = process.env.PROXMOX_E2E_PASSWORD;
const EDIT = `${BASE}/admin/products/2/edit`;

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
page.on('dialog', (d) => d.accept());
const steps = [];

async function openModulesTab() {
  await page.goto(EDIT, { waitUntil: 'domcontentloaded' });
  await page.click('#edit-tab-modules');
  await page.waitForSelector('#proxmox-templates-card');
}

try {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([
    page.waitForURL('**/admin/dashboard', { timeout: 30000 }),
    page.click('button[type="submit"]'),
  ]);
  steps.push({ step: 'login.ok' });

  // Toggle 109 ON, save, expect the reloaded page to show it checked.
  await openModulesTab();
  await page.check('#proxmox-tpl-3');
  await Promise.all([
    page.waitForURL(`${EDIT}*`, { timeout: 30000 }),
    page.click('#proxmox-templates-save'),
  ]);
  await page.click('#edit-tab-modules');
  await page.waitForSelector('#proxmox-templates-card');
  const checkedAfterAdd = await page.isChecked('#proxmox-tpl-3');
  steps.push({ step: 'restriction.add.109', checkedAfterAdd });

  // Toggle 109 OFF again, save, expect restored state.
  await page.uncheck('#proxmox-tpl-3');
  await Promise.all([
    page.waitForURL(`${EDIT}*`, { timeout: 30000 }),
    page.click('#proxmox-templates-save'),
  ]);
  await page.click('#edit-tab-modules');
  await page.waitForSelector('#proxmox-templates-card');
  const checkedAfterRemove = await page.isChecked('#proxmox-tpl-3');
  steps.push({ step: 'restriction.remove.109', checkedAfterRemove });

  // Screenshot the restored card.
  await page.screenshot({
    path: 'C:\\Users\\Administrator\\Local Sites\\managehosting\\app\\.opencode\\reports\\2026-09-27-proxmox-ui-e2e\\15-product-restriction-restored.png',
    fullPage: true,
  });
} catch (err) {
  steps.push({ step: 'FAILED', error: String(err && err.message ? err.message : err).slice(0, 400) });
  process.exitCode = 1;
} finally {
  await browser.close();
  for (const s of steps) console.log(JSON.stringify(s));
}
