import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

async function checkFontSizes() {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.setViewportSize({ width: 1366, height: 768 });
  
  // Use HTTP server for SPA routing
  await page.goto('http://localhost:3000', { waitUntil: 'networkidle', timeout: 30000 });
  
  // Wait for content to render
  await page.waitForTimeout(1000);
  
  // Get all paragraph elements
  const paragraphs = await page.evaluate(() => {
    const elements = document.querySelectorAll('p');
    const results = [];
    elements.forEach(el => {
      const text = el.textContent?.trim() || '';
      const computedStyle = window.getComputedStyle(el);
      const fontSize = parseFloat(computedStyle.fontSize);
      const parentClasses = el.parentElement?.className || '';
      const hasSmallTextClass = parentClasses.includes('text-xs') || parentClasses.includes('text-[10px]') || parentClasses.includes('text-[11px]');
      
      results.push({
        text: text.substring(0, 100),
        textLength: text.length,
        fontSize: fontSize,
        classes: el.className,
        parentClasses: parentClasses,
        isCaption: hasSmallTextClass || text.length < 50
      });
    });
    return results;
  });
  
  console.log('=== Paragraph Font Size Analysis (1366px) ===');
  console.log('');
  
  let allPass = true;
  for (const p of paragraphs) {
    const status = p.fontSize >= 14 ? 'PASS' : 'FAIL';
    if (p.fontSize < 14 && p.textLength > 50 && !p.isCaption) {
      allPass = false;
    }
    console.log(`${status} | ${p.fontSize}px | ${p.textLength} chars | ${p.isCaption ? 'CAPTION' : 'BODY'} | "${p.text}" | classes: ${p.classes || '(none)'}`);
  }
  
  console.log('');
  console.log(`=== Overall: ${allPass ? 'ALL BODY PARAGRAPHS >=14px PASS' : 'SOME BODY PARAGRAPHS <14px FAIL'} ===`);
  
  await browser.close();
  return allPass;
}

checkFontSizes().catch(console.error);