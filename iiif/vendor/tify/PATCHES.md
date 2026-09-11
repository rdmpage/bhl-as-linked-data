# Local patches to tify.js

This is a vendored copy of Tify 0.35.0 with two local fixes applied to the minified
bundle. **Re-apply these after any Tify upgrade** (or drop them once fixed upstream).

Repo: https://github.com/tify-iiif-viewer/tify

## 1. Only the first AnnotationPage on a canvas was read

Upstream: https://github.com/tify-iiif-viewer/tify/issues/346

Source: `src/plugins/store.js`, `loadAnnotations()`

```js
let resources = canvas.annotations[0].items;   // <-- [0] only
```

Every additional `AnnotationPage` in `canvas.annotations` was silently dropped, so a
canvas with e.g. a comment page and an OCR page showed only the first.

Patched to gather `items` from *all* pages (still falling back to fetching an external
annotation list when a page has no inline `items`), and to mark annotations unavailable
only when nothing at all was collected.

## 2. Region overlays were all-or-nothing per canvas

Upstream: https://github.com/tify-iiif-viewer/tify/issues/347

Source: `src/components/ViewMedia.vue` (~line 639)

```js
if (!this.$store.annotations[page]?.[0]?.coords) {
    return;                       // <-- draws no overlays at all
}
this.$store.annotations[page]?.forEach((annotation, i) => {
    ... new OpenSeadragon.Rect(annotation.coords[0] / size, ...)   // <-- assumes coords
});
```

The guard checked only the *first* annotation for coords, then the loop assumed every
annotation had them. So a canvas mixing page-level annotations (no `#xywh`) with
region-level ones drew no highlights at all — and reordering them threw a `TypeError`.

Patched to `some((a) => a.coords)` for the guard, plus an early `return` inside the loop
for annotations without coords.

## Known remaining issue (not patched)

Tify does no `motivation` filtering. With fix 1 in place, a `supplementing` OCR page is
now pulled into the annotation list alongside `commenting` annotations, so the full OCR
text appears as an entry in the Text panel.

## 3. Annotation coordinates were normalised by image width, not canvas width

Upstream: https://github.com/tify-iiif-viewer/tify/issues/348

Source: `src/components/ViewMedia.vue`, in the overlay loop

```js
firstVisibleCanvasSize = tileSource[isVertical ? 'height' : 'width'];  // <-- body.width
...
annotation.coords[0] / firstVisibleCanvasSize
```

Despite the name, `firstVisibleCanvasSize` was read from the *tile source* — i.e. the image
resource's `body.width` — not from the canvas. Per the IIIF Presentation 3 spec, `#xywh`
on a canvas target is expressed in **canvas** coordinate space, and OpenSeadragon viewport
coordinates run 0..1 across the image regardless of its pixel size. So the divisor must be
`canvas.width`.

The two agree only when `body.width == canvas.width`. For scanned material, where the
served derivative is much smaller than the scan's coordinate space, overlays were placed
off-image entirely and silently. The same value is used for the multi-page offset maths,
which was likewise mixing canvas and image units.

Patched to read the dimension from `store.manifest.items[page - 1]` (the canvas), falling
back to the tile source. Verified with both `body.width == canvas.width` (3600) and
`body.width != canvas.width` (930 vs 3600) — overlays land correctly in both.

## Note

Patch 3 is **required** for this project, not optional: canvas coordinates are kept in
the scan's coordinate space (to match Internet Archive `_djvu.xml` OCR coordinates), so
`canvas.width != body.width` on every canvas. Without patch 3, stock Tify divides
annotation coordinates by `body.width` and places every overlay off-image.

Equivalent Mirador bugs, for reference:
- https://github.com/ProjectMirador/mirador/issues/4532 (coordless annotation suppresses overlays)
- https://github.com/ProjectMirador/mirador/issues/4533 (#xywh scaled by image pixels)
