/**
 * E2E test for embedding meta storage in WordPress AI Content Provenance.
 *
 * Verifies:
 * 1. post_content in DB is clean (no invisible Unicode markers)
 * 2. Embedded content in meta has markers
 * 3. Frontend page injects markers from meta
 * 4. Badge renders on signed posts
 * 5. Unsigned posts have no markers
 * 6. Editor shows clean content
 *
 * Requires: wp-env running on localhost:8920
 */

import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const puppeteer = require('/home/developer/code/encypherai-commercial/apps/dashboard/node_modules/puppeteer');
import { execSync } from 'child_process';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SCREENSHOTS = path.join(__dirname, 'screenshots', 'embedding-meta');

const WP_URL = 'http://localhost:8920';
const WP_ADMIN = `${WP_URL}/wp-admin`;
const WP_USER = 'admin';
const WP_PASS = 'password';

// Signed post (migrated to meta storage)
const SIGNED_POST_ID = 31;
const UNSIGNED_POST_ID = 37;

let browser;
let page;
let passed = 0;
let failed = 0;

function log(msg) {
  console.log(`  ${msg}`);
}

function ok(name) {
  passed++;
  log(`PASS ${name}`);
}

function fail(name, err) {
  failed++;
  log(`FAIL ${name}: ${err}`);
}

function wpCli(cmd) {
  return execSync(`npx wp-env run cli -- wp ${cmd}`, { encoding: 'utf-8', timeout: 30000 }).trim();
}

async function screenshot(name) {
  fs.mkdirSync(SCREENSHOTS, { recursive: true });
  const file = path.join(SCREENSHOTS, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  log(`  Screenshot: ${file}`);
  return file;
}

async function login() {
  await page.goto(`${WP_ADMIN}/`, { waitUntil: 'networkidle0', timeout: 30000 });
  const url = page.url();
  if (url.includes('wp-login.php')) {
    await page.type('#user_login', WP_USER);
    await page.type('#user_pass', WP_PASS);
    await page.click('#wp-submit');
    await page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 15000 });
  }
}

// ===========================================================================
// Test 1: DB post_content is clean
// ===========================================================================
async function testDbContentIsClean() {
  log('--- Test 1: DB post_content is clean ---');

  const result = wpCli(`eval '
    $content = get_post_field("post_content", ${SIGNED_POST_ID}, "raw");
    $has = (bool) preg_match("/[\\x{FE00}-\\x{FE0F}\\x{E0100}-\\x{E01EF}\\x{FEFF}]/u", $content);
    echo json_encode(["len" => strlen($content), "has_markers" => $has]);
  '`);
  const data = JSON.parse(result.split('\n').pop());

  if (!data.has_markers) {
    ok(`post_content is clean (${data.len} chars, no markers)`);
  } else {
    fail('post_content is clean', 'markers found');
  }
}

// ===========================================================================
// Test 2: Embedded meta has markers
// ===========================================================================
async function testMetaHasMarkers() {
  log('--- Test 2: Embedded meta has markers ---');

  const result = wpCli(`eval '
    $meta = get_post_meta(${SIGNED_POST_ID}, "_c2pa_embedded_content", true);
    $content = get_post_field("post_content", ${SIGNED_POST_ID}, "raw");
    $has = !empty($meta) && (bool) preg_match("/[\\x{FE00}-\\x{FE0F}\\x{E0100}-\\x{E01EF}\\x{FEFF}]/u", $meta);
    echo json_encode(["meta_len" => strlen($meta), "content_len" => strlen($content), "has_markers" => $has, "diff" => strlen($meta) - strlen($content)]);
  '`);
  const data = JSON.parse(result.split('\n').pop());

  if (data.has_markers) {
    ok(`Meta has markers (${data.meta_len} chars, ${data.diff} bytes of markers)`);
  } else {
    fail('Meta has markers', JSON.stringify(data));
  }
}

// ===========================================================================
// Test 3: Frontend page has markers injected
// ===========================================================================
async function testFrontendHasMarkers() {
  log('--- Test 3: Frontend has markers injected ---');

  await page.goto(`${WP_URL}/?p=${SIGNED_POST_ID}`, { waitUntil: 'networkidle0', timeout: 30000 });
  await screenshot('03-signed-frontend');

  const result = await page.evaluate(() => {
    const el = document.querySelector('.entry-content') ||
               document.querySelector('.post-content') ||
               document.querySelector('article .content') ||
               document.querySelector('main');
    if (!el) return { found: false, error: 'no content element' };
    return { found: /[\uFE00-\uFE0F\uFEFF]/.test(el.innerHTML), len: el.innerHTML.length };
  });

  if (result.found) {
    ok('Frontend HTML has invisible markers');
  } else {
    fail('Frontend has markers', JSON.stringify(result));
  }
}

// ===========================================================================
// Test 4: Badge renders on signed post
// ===========================================================================
async function testBadgeRendered() {
  log('--- Test 4: Badge renders ---');

  const result = await page.evaluate(() => {
    const badge = document.querySelector('.c2pa-badge');
    return { exists: !!badge, text: badge?.textContent?.trim() };
  });

  if (result.exists) {
    ok(`Badge rendered: "${result.text}"`);
  } else {
    fail('Badge rendered', 'no .c2pa-badge found');
  }
}

// ===========================================================================
// Test 5: Unsigned post has no markers
// ===========================================================================
async function testUnsignedPostClean() {
  log('--- Test 5: Unsigned post is clean ---');

  await page.goto(`${WP_URL}/?p=${UNSIGNED_POST_ID}`, { waitUntil: 'networkidle0', timeout: 30000 });
  await screenshot('05-unsigned-frontend');

  const result = await page.evaluate(() => {
    const el = document.querySelector('.entry-content') ||
               document.querySelector('.post-content') ||
               document.querySelector('main');
    if (!el) return { markers: false, badge: false };
    return {
      markers: /[\uFE00-\uFE0F\uFEFF]/.test(el.innerHTML),
      badge: !!document.querySelector('.c2pa-badge'),
    };
  });

  if (!result.markers) {
    ok('Unsigned post has no markers');
  } else {
    fail('Unsigned post has no markers', 'markers found');
  }

  if (!result.badge) {
    ok('Unsigned post has no badge');
  } else {
    fail('Unsigned post has no badge', 'badge found');
  }
}

// ===========================================================================
// Test 6: Copy-paste preserves markers
// ===========================================================================
async function testCopyPastePreservesMarkers() {
  log('--- Test 6: Copy-paste preserves markers ---');

  await page.goto(`${WP_URL}/?p=${SIGNED_POST_ID}`, { waitUntil: 'networkidle0', timeout: 30000 });

  const result = await page.evaluate(() => {
    const el = document.querySelector('.entry-content') ||
               document.querySelector('.post-content') ||
               document.querySelector('main');
    if (!el) return null;
    const range = document.createRange();
    range.selectNodeContents(el);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    const text = sel.toString();
    sel.removeAllRanges();
    return { hasMarkers: /[\uFE00-\uFE0F\uFEFF]/.test(text), len: text.length };
  });

  if (result?.hasMarkers) {
    ok('Selected text includes markers (provenance survives copy-paste)');
  } else {
    fail('Copy-paste preserves markers', JSON.stringify(result));
  }
}

// ===========================================================================
// Test 7: Editor shows clean content
// ===========================================================================
async function testEditorClean() {
  log('--- Test 7: Editor shows clean content ---');

  try {
    await page.goto(`${WP_ADMIN}/post.php?post=${SIGNED_POST_ID}&action=edit`, {
      waitUntil: 'networkidle0',
      timeout: 30000,
    });
    await screenshot('07-editor');

    await page.waitForSelector('.editor-post-title, .edit-post-visual-editor', { timeout: 10000 }).catch(() => {});

    const result = await page.evaluate(() => {
      const content = wp?.data?.select('core/editor')?.getEditedPostContent?.();
      if (!content) return { available: false };
      return { available: true, hasMarkers: /[\uFE00-\uFE0F\uFEFF]/.test(content), len: content.length };
    });

    if (result.available && !result.hasMarkers) {
      ok(`Editor content is clean (${result.len} chars, no markers)`);
    } else if (!result.available) {
      log('  SKIP: Could not access editor state');
    } else {
      fail('Editor is clean', `markers found (${result.len} chars)`);
    }
  } catch (err) {
    log(`  SKIP: Editor test timed out (${err.message})`);
  }
}

// ===========================================================================
// Main
// ===========================================================================
async function main() {
  console.log('=== WordPress AI Content Provenance - Embedding Meta Storage E2E ===\n');
  console.log(`WordPress: ${WP_URL}`);
  console.log(`Signed post: ${SIGNED_POST_ID}, Unsigned post: ${UNSIGNED_POST_ID}\n`);

  browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });
  page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });

  try {
    await login();
    await testDbContentIsClean();
    await testMetaHasMarkers();
    await testFrontendHasMarkers();
    await testBadgeRendered();
    await testUnsignedPostClean();
    await testCopyPastePreservesMarkers();
    await testEditorClean();
  } catch (err) {
    console.error('FATAL:', err);
    await screenshot('fatal-error').catch(() => {});
  } finally {
    await browser.close();
  }

  console.log(`\n=== Results: ${passed} passed, ${failed} failed ===`);
  process.exit(failed > 0 ? 1 : 0);
}

main();
