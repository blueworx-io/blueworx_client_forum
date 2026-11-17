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
// The rule under test: the featured image comes first, both on show and in the
// thumbnail strip, then the gallery in its own order — so whatever sits last in
// the gallery (the dimensional drawing) comes last.

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
async function productPage(page, content, meta = {}) {
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
      meta: { _product_image_gallery: `${first.id},${second.id}`, ...meta },
    },
  });
  expect(created.ok(), `page: ${created.status()}`).toBeTruthy();
  const host = await created.json();
  expect(host.meta._product_image_gallery, 'gallery meta was not stored').toBe(
    `${first.id},${second.id}`
  );

  return { id: host.id, featured, first, second };
}

test('WooCommerce source shows the featured image first and lists it first in the thumbnails', async ({
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
  await expect(thumbs.nth(0)).toHaveAttribute('data-epi-full', new RegExp(featured.slug));
  await expect(thumbs.nth(0)).toHaveClass(/is-active/);
  await expect(thumbs.nth(1)).toHaveAttribute('data-epi-full', new RegExp(first.slug));
  await expect(thumbs.nth(2)).toHaveAttribute('data-epi-full', new RegExp(second.slug));
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

  // Back from the first image wraps round to the last.
  await prev.click();
  await expect(main).toHaveAttribute('src', new RegExp(second.slug));
});

// ePim answers a picture link with its 300px thumbnail, and with a larger copy
// when the link asks for one (?w=…&h=…, both needed). The product page asks for
// 1200px on the main picture and the zoom; the thumbnail strip keeps the small one.
const epim = (media) => `https://epim.online/webproduct/assetimage/${media.id}.jpg`;
const epimLarge = (media) => `${epim(media)}?w=1200&h=1200`;

// The test images are media library ids, which ePim does not have. Answer every
// ePim request with a pixel so no picture fails over to the placeholder.
async function stubEpim(page) {
  await page.route('https://epim.online/**', (route) =>
    route.fulfill({ status: 200, contentType: 'image/png', body: PIXEL })
  );
}

async function expectEpimGallery(page, { featured, first, second }) {
  const thumbs = page.locator('.epi-gallery .epi-gallery__thumb');

  await expect(thumbs).toHaveCount(3);
  await expect(thumbs.nth(0)).toHaveAttribute('data-epi-full', epimLarge(featured));
  await expect(thumbs.nth(1)).toHaveAttribute('data-epi-full', epimLarge(first));
  await expect(thumbs.nth(2)).toHaveAttribute('data-epi-full', epimLarge(second));

  await expect(thumbs.nth(0).locator('img')).toHaveAttribute('src', epim(featured));
  await expect(thumbs.nth(1).locator('img')).toHaveAttribute('src', epim(first));
  await expect(thumbs.nth(2).locator('img')).toHaveAttribute('src', epim(second));

  await expect(page.locator('.epi-gallery__main-image')).toHaveAttribute(
    'src',
    epimLarge(featured)
  );
}

test('ePim source shows the large picture on the main image and the small one in the thumbnails', async ({
  page,
}) => {
  const media = await productPage(page, '[forum_product_gallery source="epim"]');
  await stubEpim(page);
  await page.goto(`/?page_id=${media.id}`);

  await expectEpimGallery(page, media);
});

// If ePim ever refuses a sized request, the shopper should still get the
// standard picture, not the placeholder.
//
// The browser picture store's worker can take over the page while it loads, and
// requests it makes skip page.route and reach the real ePim, which has none of
// these test pictures. These two tests are about the gallery, so it stays out.
test.describe('when ePim refuses pictures', () => {
  test.use({ serviceWorkers: 'block' });

  test('when ePim refuses the large picture, the gallery falls back to the standard one', async ({
    page,
  }) => {
    const { id, featured } = await productPage(page, '[forum_product_gallery source="epim"]');
    await page.route('https://epim.online/**', (route) =>
      new URL(route.request().url()).searchParams.has('w')
        ? route.fulfill({
            status: 500,
            contentType: 'text/html',
            body: 'Server Error',
          })
        : route.fulfill({ status: 200, contentType: 'image/png', body: PIXEL })
    );
    await page.goto(`/?page_id=${id}`);

    const main = page.locator('.epi-gallery__main-image');
    await expect(main).toHaveAttribute('src', epim(featured));
    await expect
      .poll(() => main.evaluate((img) => img.complete && img.naturalWidth))
      .toBeGreaterThan(0);

    await page.locator('.epi-gallery .epi-gallery__enlarge').click();
    await expect(page.locator('.epi-lightbox__image')).toHaveAttribute('src', epim(featured));
  });

  test('when ePim refuses every picture, the gallery shows the placeholder', async ({ page }) => {
    const { id } = await productPage(page, '[forum_product_gallery source="epim"]');
    await page.route('https://epim.online/**', (route) =>
      route.fulfill({
        status: 500,
        contentType: 'text/html',
        body: 'Server Error',
      })
    );
    await page.goto(`/?page_id=${id}`);

    await expect(page.locator('.epi-gallery__main-image')).toHaveAttribute('src', /\.svg$/);
  });
});

// ePim takes seconds to make a large picture the first time it is asked. A click
// must not leave the old picture on show meanwhile: it shows the small one the
// thumbnail strip already has, then the large one once it has arrived.
test.describe('when ePim is slow to send the large picture', () => {
  test.use({ serviceWorkers: 'block' });

  test('a click shows the picture at once and sharpens it when the large one arrives', async ({
    page,
  }) => {
    const { id, first, second } = await productPage(page, '[forum_product_gallery source="epim"]');

    // Large pictures wait until the test lets them go.
    const held = [];
    await page.route('https://epim.online/**', (route) => {
      if (new URL(route.request().url()).searchParams.has('w')) {
        held.push(route);
        return;
      }
      return route.fulfill({ status: 200, contentType: 'image/png', body: PIXEL });
    });
    // Let go of the large pictures, once the page has asked for one.
    const release = async () => {
      await expect.poll(() => held.length).toBeGreaterThan(0);
      while (held.length) {
        await held.shift().fulfill({ status: 200, contentType: 'image/png', body: PIXEL });
      }
    };

    await page.goto(`/?page_id=${id}`, { waitUntil: 'domcontentloaded' });
    const main = page.locator('.epi-gallery__main-image');
    await release();
    await expect
      .poll(() => main.evaluate((img) => img.complete && img.naturalWidth))
      .toBeGreaterThan(0);

    await page.locator('.epi-gallery__arrow--next').click();
    await expect(main).toHaveAttribute('src', epim(first), { timeout: 1000 });

    await release();
    await expect(main).toHaveAttribute('src', epimLarge(first));

    // The zoom works the same way.
    await page.locator('.epi-gallery .epi-gallery__enlarge').click();
    const zoomed = page.locator('.epi-lightbox__image');
    await page.locator('.epi-lightbox__arrow--next').click();
    await expect(zoomed).toHaveAttribute('src', epim(second), { timeout: 1000 });

    await release();
    await expect(zoomed).toHaveAttribute('src', epimLarge(second));
  });
});

// The zoom asks ePim for pictures after the page has loaded, by which time the
// browser picture store's worker can be answering them from the real ePim (see
// above), so it stays out here too.
test.describe('the zoom', () => {
  test.use({ serviceWorkers: 'block' });

  test('ePim source opens the large picture in the zoom, and the arrows stay large', async ({
    page,
  }) => {
    const { id, featured, first } = await productPage(
      page,
      '[forum_product_gallery source="epim"]'
    );
    await stubEpim(page);
    await page.goto(`/?page_id=${id}`);

    const modal = page.locator('.epi-lightbox');
    const zoomed = modal.locator('.epi-lightbox__image');

    await page.locator('.epi-gallery .epi-gallery__enlarge').click();
    await expect(zoomed).toHaveAttribute('src', epimLarge(featured));

    await modal.locator('.epi-lightbox__arrow--next').click();
    await expect(zoomed).toHaveAttribute('src', epimLarge(first));
  });
});

// While the shop moves over to ePim pictures a category at a time, the gallery
// stays set to the media library. A product the pull has already switched holds
// ePim picture numbers, so it must be read as ePim's whatever the gallery says.
test('a product marked as using ePim pictures shows them even when the gallery is set to the media library', async ({
  page,
}) => {
  const media = await productPage(page, '[forum_product_gallery source="woocommerce"]', {
    _epim_pictures: '1',
  });
  await stubEpim(page);
  await page.goto(`/?page_id=${media.id}`);

  await expectEpimGallery(page, media);
});

test('the enlarge button opens the image in a modal, with the arrows still looping', async ({
  page,
}) => {
  const { id, featured, first, second } = await productPage(
    page,
    '[forum_product_gallery source="woocommerce"]'
  );
  await page.goto(`/?page_id=${id}`);

  const gallery = page.locator('.epi-gallery');
  const modal = page.locator('.epi-lightbox');
  const large = modal.locator('.epi-lightbox__image');

  await expect(modal).toBeHidden();
  await gallery.locator('.epi-gallery__enlarge').click();

  await expect(modal).toBeVisible();
  await expect(modal).toHaveAttribute('role', 'dialog');
  await expect(large).toHaveAttribute('src', new RegExp(featured.slug));
  await expect(modal.locator('.epi-lightbox__count')).toHaveText('1 / 3');

  await modal.locator('.epi-lightbox__arrow--next').click();
  await expect(large).toHaveAttribute('src', new RegExp(first.slug));
  await expect(modal.locator('.epi-lightbox__count')).toHaveText('2 / 3');

  await page.keyboard.press('ArrowRight');
  await expect(large).toHaveAttribute('src', new RegExp(second.slug));

  // The gallery behind keeps step, so closing leaves it where the shopper was.
  await page.keyboard.press('Escape');
  await expect(modal).toBeHidden();
  await expect(gallery.locator('.epi-gallery__main-image')).toHaveAttribute(
    'src',
    new RegExp(second.slug)
  );
});

test('the modal fits a phone and closes on the overlay', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 667 });
  const { id } = await productPage(page, '[forum_product_gallery source="woocommerce"]');
  await page.goto(`/?page_id=${id}`);

  await page.locator('.epi-gallery__enlarge').click();
  const modal = page.locator('.epi-lightbox');
  await expect(modal).toBeVisible();

  const box = await modal.locator('.epi-lightbox__image').boundingBox();
  expect(box.width).toBeLessThanOrEqual(375);
  expect(box.height).toBeLessThanOrEqual(667);

  await modal.locator('.epi-lightbox__overlay').click({ position: { x: 5, y: 5 } });
  await expect(modal).toBeHidden();
});

// The live product template shows the picture at the full width of its column,
// square, edge to edge — this gallery replaces it, so it must take up the same
// space or the page shifts.
test('the main image fills the full width of the gallery and is square', async ({ page }) => {
  const { id } = await productPage(page, '[forum_product_gallery source="woocommerce"]');
  await page.goto(`/?page_id=${id}`);

  const gallery = await page.locator('.epi-gallery').boundingBox();
  const main = await page.locator('.epi-gallery__main-image').boundingBox();

  expect(main.width).toBe(gallery.width);
  expect(main.height).toBe(main.width);
  expect(main.x).toBe(gallery.x);

  // Rounded like the site's product cards, not square.
  await expect(page.locator('.epi-gallery__stage')).toHaveCSS('border-radius', '12px');
  await expect(page.locator('.epi-gallery__thumb').first()).toHaveCSS('border-radius', '8px');
});
