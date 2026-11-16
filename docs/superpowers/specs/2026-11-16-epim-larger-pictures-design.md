# Larger ePim pictures on the product page — design

Approved by Luke, 2026-11-16. Forge: "Migrate Forum Onto New Import Method".

## Why

ePim sends 300px pictures, which look soft on the product page and when enlarged.
Mel (NG15, 12 Nov) confirmed a larger size is available by adding `?w=N&h=N` to
the picture link; both are needed or ePim returns the 300px thumbnail. Luke held
moving the remaining categories until this was answered.

## What

- Product page main picture and the zoom (lightbox): ask ePim for 1200px.
- Gallery thumbnails, shop cards, cart, checkout, related products: stay at the plain 300px link.

Tested 16 Nov: ePim keeps the aspect ratio and never enlarges past the original
(one picture stayed 296x273, another capped at 1000px). Some originals are over
1MB, which is why listings keep the small size.

## Goals / non-goals

- Goal: sharper product page pictures with no change to how pictures are stored or pulled.
- Non-goal: storing or proxying pictures on Forum's server (ruled out by Luke).
- Non-goal: changing the import. Pictures are stored as numbers; size is added when the page is drawn.
- Anti-goal: slower shop listings.

## Approaches

1. **Size added when the page is drawn (chosen).** The URL builder takes an optional size; the
   product page asks for large, everything else for the default. No re-import, and the size can change later in one place.
2. One large size everywhere. Simplest, but listings would download pictures up to 1MB+ each.
3. Save sized links during the import. Changing the size later would mean importing again.

## Builder notes

- The size parameters must be added correctly whether or not the base address already has a
  query string (the test stand-in's base does).
- The browser picture store matches by the ePim address prefix, so sized links are kept too.
- Builder's call: 1200 lives in one constant with a filter so it can be tuned without a release of the logic.
