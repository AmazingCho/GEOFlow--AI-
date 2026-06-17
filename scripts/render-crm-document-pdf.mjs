import { pathToFileURL } from 'node:url';
import fs from 'node:fs/promises';
import puppeteer from 'puppeteer';

const [htmlPath, pdfPath] = process.argv.slice(2);

if (!htmlPath || !pdfPath) {
  console.error('Usage: node scripts/render-crm-document-pdf.mjs <input.html> <output.pdf>');
  process.exit(2);
}

await fs.access(htmlPath);

const executablePath = process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium';
const browser = await puppeteer.launch({
  executablePath,
  headless: 'new',
  args: [
    '--no-sandbox',
    '--disable-setuid-sandbox',
    '--allow-file-access-from-files',
    '--disable-dev-shm-usage',
    '--font-render-hinting=none',
  ],
});

try {
  const page = await browser.newPage();
  await page.setViewport({ width: 1240, height: 1754, deviceScaleFactor: 1 });
  await page.goto(pathToFileURL(htmlPath).href, {
    waitUntil: ['load', 'networkidle0'],
    timeout: 60000,
  });
  await page.emulateMediaType('print');
  await page.pdf({
    path: pdfPath,
    format: 'A4',
    printBackground: true,
    preferCSSPageSize: true,
    margin: {
      top: '0mm',
      right: '0mm',
      bottom: '0mm',
      left: '0mm',
    },
    timeout: 60000,
  });
} finally {
  await browser.close();
}
