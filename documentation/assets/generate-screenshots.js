const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const assetsDir = __dirname;
const outDir = path.join(assetsDir, 'screenshots');

const captures = [
  'capture-01-menu.html',
  'capture-02-settings-urls.html',
  'capture-03-settings-sftp.html',
  'capture-04-pages-sync.html',
  'capture-05-file-sync.html',
  'capture-06-acf-sync.html',
  'capture-07-logs.html',
  'capture-08-mcp.html',
];

(async () => {
  if (!fs.existsSync(outDir)) fs.mkdirSync(outDir, { recursive: true });

  const browser = await chromium.launch();
  const page = await browser.newPage({ deviceScaleFactor: 2 });

  for (const file of captures) {
    const htmlPath = path.join(assetsDir, file);
    const name = file.replace('.html', '').replace('capture-', '') + '.png';
    const outPath = path.join(outDir, name);

    await page.goto('file://' + htmlPath, { waitUntil: 'networkidle' });
    const el = await page.$('#capture');
    if (el) {
      await el.screenshot({ path: outPath });
      console.log('Saved', name);
    }
  }

  await browser.close();
})();
