import { pathToFileURL } from 'node:url';
import fs from 'node:fs/promises';
import path from 'node:path';
import puppeteer from 'puppeteer';

const [htmlPath, outputDir, fileStem] = process.argv.slice(2);

if (!htmlPath || !outputDir || !fileStem) {
  console.error('Usage: node scripts/render-crm-document-screenshots.mjs <input.html> <output-dir> <file-stem>');
  process.exit(2);
}

await fs.access(htmlPath);
await fs.mkdir(outputDir, { recursive: true });

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

  const pageHandles = await page.$$('.page');
  const screenshots = [];

  for (let index = 0; index < pageHandles.length; index += 1) {
    const screenshotPath = path.join(outputDir, `${fileStem}-page-${index + 1}-of-${pageHandles.length}.png`);
    await pageHandles[index].screenshot({ path: screenshotPath });
    screenshots.push(screenshotPath);
  }

  console.log(JSON.stringify({
    htmlPages: pageHandles.length,
    screenshots,
  }));
} finally {
  await browser.close();
}
