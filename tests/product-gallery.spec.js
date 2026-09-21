const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers');

// The product gallery has two ways in: the Elementor widget and the
// [forum_product_gallery] shortcode. The harness has neither Elementor nor
// WooCommerce, so the specs use the shortcode on a plain page. A page keeps its
// featured image where a product does, and the gallery meta is opened to REST
// by a test-only mu-plugin (tests/support/) that the spec installs itself.
//
// The rule under test: the featured image is what the shopper sees first, but
// in the thumbnail strip it comes last, so stepping through the images from it
// runs the gallery and then loops back round.

const SUPPORT = path.resolve(__dirname, 'support', 'epi-test-gallery-meta.php');
const MU_DIR = path.resolve(__dirname, '..', '.wp-test', 'wp', 'wp-content', 'mu-plugins');

test.beforeAll(() => {
  fs.mkdirSync(MU_DIR, { recursive: true });
  fs.copyFileSync(SUPPORT, path.join(MU_DIR, path.basename(SUPPORT)));
});

// The smallest valid PNG: one white pixel. What it looks like does not matter;
// the gallery is asserted on where each image came from, not what it shows.
const PIXEL = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=',
  'base64'
);

async function restNonce(page) {
  return page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) =>
      r.text()
    )
  );
}

async function upload(page, nonce, name) {
  const res = await page.request.post('/wp-json/wp/v2/media', {
    headers: { 'X-WP-Nonce': nonce },
    multipart: { file: { name: `${name}.png`, mimeType: 'image/png', buffer: PIXEL } },
  });
  expect(res.ok(), `upload ${name}: ${res.status()}`).toBeTruthy();
  return res.json();
}

// A page standing in for a product: featured image plus two gallery images.
async function productPage(page, content) {
  await loginAsAdmin(page);
  const nonce = await restNonce(page);
  const stamp = Date.now();
  const featured = await upload(page, nonce, `featured-${stamp}`);
  const first = await upload(page, nonce, `gallery-one-${stamp}`);
  const second = await upload(page, nonce, `gallery-two-${stamp}`);

  const created = await page.request.post('/wp-json/wp/v2/pages', {
    headers: { 'X-WP-Nonce': nonce },
    data: {
      title: `Gallery host ${stamp}`,
      content,
      status: 'publish',
      featured_media: featured.id,
      meta: { _product_image_gallery: `${first.id},${second.id}` },
    },
  });
  expect(created.ok(), `page: ${created.status()}`).toBeTruthy();
  const host = await created.json();
  expect(host.meta._product_image_gallery, 'gallery meta was not stored').toBe(
    `${first.id},${second.id}`
  );

  return { id: host.id, featured, first, second };
}

test('WooCommerce source shows the featured image first and lists it last in the thumbnails', async ({
  page,
}) => {
  const { id, featured, first, second } = await productPage(
    page,
    '[forum_product_gallery source="woocommerce"]'
  );
  await page.goto(`/?page_id=${id}`);

  const gallery = page.locator('.epi-gallery');
  const main = gallery.locator('.epi-gallery__main-image');
  const thumbs = gallery.locator('.epi-gallery__thumb');

  await expect(main).toHaveAttribute('src', new RegExp(featured.slug));
  await expect(thumbs).toHaveCount(3);
  await expect(thumbs.nth(0)).toHaveAttribute('data-epi-full', new RegExp(first.slug));
  await expect(thumbs.nth(1)).toHaveAttribute('data-epi-full', new RegExp(second.slug));
  await expect(thumbs.nth(2)).toHaveAttribute('data-epi-full', new RegExp(featured.slug));
  await expect(thumbs.nth(2)).toHaveClass(/is-active/);
});

test('the arrows loop through every image and back round to the featured one', async ({ page }) => {
  const { id, featured, first, second } = await productPage(
    page,
    '[forum_product_gallery source="woocommerce"]'
  );
  await page.goto(`/?page_id=${id}`);

  const gallery = page.locator('.epi-gallery');
  const main = gallery.locator('.epi-gallery__main-image');
  const next = gallery.locator('.epi-gallery__arrow--next');
  const prev = gallery.locator('.epi-gallery__arrow--prev');

  await next.click();
  await expect(main).toHaveAttribute('src', new RegExp(first.slug));
  await next.click();
  await expect(main).toHaveAttribute('src', new RegExp(second.slug));
  await next.click();
  await expect(main).toHaveAttribute('src', new RegExp(featured.slug));

  await prev.click();
  await expect(main).toHaveAttribute('src', new RegExp(second.slug));
});

test('ePim source builds the same order from the ids as ePim asset URLs', async ({ page }) => {
  const { id, featured, first, second } = await productPage(
    page,
    '[forum_product_gallery source="epim"]'
  );
  await page.goto(`/?page_id=${id}`);

  const thumbs = page.locator('.epi-gallery .epi-gallery__thumb');
  const epim = (media) => `https://epim.online/webproduct/assetimage/${media.id}.jpg`;

  await expect(thumbs).toHaveCount(3);
  await expect(thumbs.nth(0)).toHaveAttribute('data-epi-full', epim(first));
  await expect(thumbs.nth(1)).toHaveAttribute('data-epi-full', epim(second));
  await expect(thumbs.nth(2)).toHaveAttribute('data-epi-full', epim(featured));
});
