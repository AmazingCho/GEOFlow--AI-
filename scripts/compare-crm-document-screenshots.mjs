import fs from 'node:fs/promises';
import path from 'node:path';
import { PNG } from 'pngjs';
import pixelmatch from 'pixelmatch';

const [baselineReportPath, currentReportPath, outputDir, thresholdInput] = process.argv.slice(2);

if (!baselineReportPath || !currentReportPath || !outputDir) {
  console.error('Usage: node scripts/compare-crm-document-screenshots.mjs <baseline-report.json> <current-report.json> <output-dir> [ratio-threshold]');
  process.exit(2);
}

const ratioThreshold = Number.isFinite(Number.parseFloat(thresholdInput || ''))
  ? Number.parseFloat(thresholdInput)
  : 0.01;

const readJson = async (filePath) => JSON.parse(await fs.readFile(filePath, 'utf8'));
const readPng = async (filePath) => PNG.sync.read(await fs.readFile(filePath));
const byType = (report) => new Map((report.results || []).map((row) => [row.document_type, row]));

await fs.mkdir(outputDir, { recursive: true });

const baselineReport = await readJson(baselineReportPath);
const currentReport = await readJson(currentReportPath);
const baselineRows = byType(baselineReport);
const results = [];

let overallStatus = 'passed';

for (const currentRow of currentReport.results || []) {
  const documentType = currentRow.document_type;
  const baselineRow = baselineRows.get(documentType);
  if (!baselineRow) {
    overallStatus = 'changed';
    results.push({
      document_type: documentType,
      status: 'missing_baseline',
      message: 'No baseline row exists for this document type.',
      pages: [],
    });
    continue;
  }

  const baselineScreenshots = baselineRow.screenshots || [];
  const currentScreenshots = currentRow.screenshots || [];
  if (baselineScreenshots.length !== currentScreenshots.length) {
    overallStatus = 'page_mismatch';
    results.push({
      document_type: documentType,
      status: 'page_mismatch',
      baseline_pages: baselineScreenshots.length,
      current_pages: currentScreenshots.length,
      pages: [],
    });
    continue;
  }

  const pages = [];
  let documentMaxRatio = 0;
  let documentStatus = 'passed';

  for (let index = 0; index < currentScreenshots.length; index += 1) {
    const baselineImagePath = baselineScreenshots[index];
    const currentImagePath = currentScreenshots[index];
    const baselineImage = await readPng(baselineImagePath);
    const currentImage = await readPng(currentImagePath);

    if (baselineImage.width !== currentImage.width || baselineImage.height !== currentImage.height) {
      documentStatus = 'page_mismatch';
      overallStatus = 'page_mismatch';
      pages.push({
        page: index + 1,
        status: 'page_mismatch',
        baseline_width: baselineImage.width,
        baseline_height: baselineImage.height,
        current_width: currentImage.width,
        current_height: currentImage.height,
      });
      continue;
    }

    const diff = new PNG({ width: currentImage.width, height: currentImage.height });
    const mismatchedPixels = pixelmatch(
      baselineImage.data,
      currentImage.data,
      diff.data,
      currentImage.width,
      currentImage.height,
      { threshold: 0.1 }
    );
    const totalPixels = currentImage.width * currentImage.height;
    const diffRatio = totalPixels > 0 ? mismatchedPixels / totalPixels : 0;
    documentMaxRatio = Math.max(documentMaxRatio, diffRatio);

    const diffPath = path.join(outputDir, `${documentType}-page-${index + 1}-diff.png`);
    await fs.writeFile(diffPath, PNG.sync.write(diff));

    const pageStatus = diffRatio > ratioThreshold ? 'changed' : 'passed';
    if (pageStatus === 'changed' && documentStatus === 'passed') {
      documentStatus = 'changed';
    }
    pages.push({
      page: index + 1,
      status: pageStatus,
      diff_ratio: diffRatio,
      mismatched_pixels: mismatchedPixels,
      total_pixels: totalPixels,
      baseline_screenshot: baselineImagePath,
      current_screenshot: currentImagePath,
      diff_path: diffPath,
    });
  }

  if (documentStatus === 'changed' && overallStatus === 'passed') {
    overallStatus = 'changed';
  }

  results.push({
    document_type: documentType,
    status: documentStatus,
    max_diff_ratio: documentMaxRatio,
    threshold: ratioThreshold,
    pages,
  });
}

console.log(JSON.stringify({
  status: overallStatus,
  message: overallStatus === 'passed'
    ? 'Current print screenshots match the configured baseline.'
    : 'Current print screenshots differ from the configured baseline.',
  threshold: ratioThreshold,
  render_context: currentReport.render_context || {},
  baseline_report: baselineReportPath,
  current_report: currentReportPath,
  diff_directory: outputDir,
  results,
}));
