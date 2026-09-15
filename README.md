# BHL as linked data

Experiments to render BHL as linked data. 

Note that the goal is not to simply map BHL data dump tables onto arbitrary RDF, but model BHL data such that we could, in principle, build a BHL web interface using SPARQL queries. Leaving aside the wisdom of trying to run BHL on a triple store, setting that as a goal helps clarify the modelling. 

One goal, for example, is to be be able to generate a IIIF manifest for a BHL item from the RDF. This enables us to visually test whether our model works (e.g., can we support page order, the relationship between parts and items). And because a IIIF viewer natuively speaks RDF (the manifest file a viewer needs is JSON-LD), it also means that we think about images and text as annotations, along with more obvious annotations such as taxonomic names, etc.


## Vocabulary

Wherever possible we use [schema.org](https://schema.org) with the **https** protocol (see [Is it http://schema.org or https://schema.org?](https://docs.nde.nl/blog/2026/03/09/schema.org/)).

## IIIF

Use IIIF Presentation API version 3 to model a BHL item. The key idea behind the IIIF model is that there is a virtual page (the “canvas”) which we annotate. **Everything is an annotation**, the page image, the OCR text, etc. This enables us to think about having multiple page images for the same page (e.g, an original scan image, a highly compressed black and white image, etc.), as well as having multiple text annotations (for example, OCR output provided by Internet Archive, as well as more powerful LLM-based tools). We can also have annotations for blocks of text or words, such as taxonomic names. The model allows for different versions of the data.

To explore this idea, we take an Internet Archive scandata.xml file and convert it to a IIIF `manifest.json` file. The canvas (virtual page) dimensions are the cropbox width and height in the scandata file, and we can compute the approximate image sizes for the _thumbnail and _large WEBP images stored on [AWS](https://registry.opendata.aws/bhl-open-data/) based on standard widths of 150 and 930 pixels, respectively. Alternatively we can use the _full image which has the same dimensions as the scan.

We can treat OCR text as a canvas-level annotation, and also add smaller annotations (such as location of taxonomic names on a page). The canvas dimensions are the same as page dimensions in the Internet Archive OCR outputs, so we can use those coordinates directly. Note that for LLM-based OCR tools we will may have text-based rather than coordinate-based annotations, as the output from those tools often do not include word-level coordinates.

### IIIF viewers

In exploring the generated IIIF manifests I used the [Tify](https://tify.rocks) and [Mirador](https://projectmirador.org) viewers, and uncovered a number of limitations and "gotchas”. The dicovery and decsription of the issues below was prepared with the help of Claude Code.

Annotations can refer to the whole canvas, or to a specific region of it. It seems logical for a page-level annotation to target the bare canvas URI, but both viewers mishandle this: if any annotation on a canvas lacks an #xywh fragment, no region overlays are drawn for that canvas at all, including for annotations that do have one (Tify #347 (https://github.com/tify-iiif-viewer/tify/issues/347), Mirador #4532 (https://github.com/ProjectMirador/mirador/issues/4532)). The annotations still appear in the text panel — only the boxes go missing.

There is also an issue with multiple AnnotationPages on the same canvas. Tify reads only the first one and silently ignores the rest (Tify #346 (https://github.com/tify-iiif-viewer/tify/issues/346)), so a canvas that separates, say, comments from OCR text will only ever show one of them. Multiple annotations within a single AnnotationPage work fine.

Most significantly, the notional canvas and the image drawn onto it are separate coordinate spaces. An Internet Archive canvas might be 3600 pixels wide while the large derivative we actually draw from AWS is 930 pixels wide. Both viewers assume the two are the same, so annotation coordinates expressed in canvas units — as the spec requires — are drawn at the wrong scale, in this case 3600/930 ≈ 3.87× too large. The two get it wrong differently: Tify divides by the declared body.width (Tify #348 (https://github.com/tify-iiif-viewer/tify/issues/348)), so declaring the image at canvas dimensions accidentally makes it work, whereas Mirador ignores the declaration entirely and uses the decoded image's true pixel size (Mirador #4533 (https://github.com/ProjectMirador/mirador/issues/4533)), so no manifest-level change helps.

Canvas coordinates are worth persevering with for Internet Archive content, because they match the coordinates in IA's OCR files. The <OBJECT> dimensions in _djvu.xml correspond to the <cropBox> dimensions in _scandata.xml — 3600 × 4831 for the pages above — so annotation coordinates taken straight from the OCR need no transformation to align with the canvas. Scaling them into the derivative's coordinate space would make Mirador render correctly today, but would tie every coordinate to whatever size the derivative happens to be.

### Ranges (tables of contents)

BHL parts — the articles within an item — become IIIF `Range`s, listed in the manifest's `structures`. A viewer renders these as a table of contents, which makes the part/item relationship something you can see rather than infer. Each range carries the part's title as its label and references the canvases for that part's pages.

The ranges are built by a SPARQL `SELECT` of their own (`construct_ranges()`) rather than by adding patterns to the manifest `CONSTRUCT`, for two reasons that only became apparent once ranges covered every page of a part rather than just the first.

The first is cost. Joined into the manifest query, the parts multiply against the pages: for item 223011 that is 220 pages × 211 part pages = 52,117 solutions to produce 6,447 distinct triples, roughly 88% of the work discarded, and the waste grows with the product of the two rather than their sum. Asked separately the cost is just the number of part pages.

The second is order, and it is the more insidious of the two. A `CONSTRUCT` returns an unordered set of triples — an endpoint is under no obligation to preserve an `ORDER BY` when serialising one — so the canvases within each range came back scrambled: range 1 held pages 3, 4, 7, 5, 6. Every one of the 27 ranges was affected. With a single page per range this is invisible, which is exactly what makes it dangerous. A `SELECT` returns a sequence, so `ORDER BY ?part_position ?page_position` gives both the order of the ranges and the order of pages within each one, and no sorting is needed afterwards.

### JSON-LD framing, and why the manifest is assembled by hand

The manifest is produced by framing the RDF returned by the `CONSTRUCT`, but several parts of it cannot come out of the framing and are fixed up in PHP afterwards.

Framing embeds a node under *every* property that points at it, rather than embedding it once and referencing it elsewhere. A range reached through framing therefore carries a complete second copy of each of its pages — painting annotation, thumbnail, OCR body and all — instead of a reference. With one page per range this cost about 13%; with every page of every part it doubled the manifest outright, from 610 KB to 1212 KB. Building `structures` in PHP sidesteps this entirely, because the page canvases never enter the framed document. It is worth knowing that this cuts both ways: the same behaviour is why `items` survived intact rather than being silently downgraded to bare references, and any further property hung off canvases will produce yet another full copy.

Labels are a separate problem. IIIF requires a language map, `{"none": ["Page 1"]}`, and `ml/json-ld` is a JSON-LD 1.0 processor that cannot produce that shape: a `@container` of `["@language", "@set"]` is rejected outright, a plain `"@language"` container yields strings rather than the arrays IIIF wants, and there is no `@none` to file untagged literals under. So labels are emitted as ordinary literals and converted by `language_map()` on the way out.

### The `@context`, and whether anything calls home

The manifest declares `"@context": "http://iiif.io/api/presentation/3/context.json"`, but that string is written straight into the output rather than handed to the processor. The official context is `@version` 1.1 — it uses scoped contexts, an `["@language", "@set"]` container on `label`, and `@none` — and asking `ml/json-ld` 1.2.1 to compact against it throws. The internal context in `construct_iiif()` is what actually does the compaction, and it is not a shortcut so much as the only option short of changing libraries.

That makes it worth checking that the two agree, since declaring a context is a promise about how the JSON should be read. They do, on the IRI of every term the manifest emits, with one exception that was a genuine bug: the official context maps `format` to `http://purl.org/dc/elements/1.1/format`, not `dcterms:format`. The JSON is identical either way, so the manifest said one thing and meant another, and the discrepancy only surfaces on a round trip back to RDF. It now emits the element-set IRI.

The remaining difference is containers — the official context declares `items`, `structures` and `annotations` as `@list` where the internal one uses `@set`. This changes nothing in the JSON, both being arrays, and shows only on expansion back to RDF, where `@list` additionally asserts the page order the arrays are already sorted into. The official reading is the stronger of the two rather than a contradiction.

On the question of JSON-LD tools quietly fetching contexts over the network: nothing here does. Running the whole pipeline with PHP's `http` and `https` stream wrappers unregistered and replaced by recorders, parsing, `fromRdf` and framing complete with zero network accesses. What matters is how the context is passed. Inline, as an object, there is nothing to dereference; passed as a URL string the same library fetches it immediately. The only call the script makes is the SPARQL query itself. A *consumer* expanding the manifest with the official context will fetch it, and also `http://www.w3.org/ns/anno.jsonld` which it imports inside the Annotation scopes, but that is their side rather than ours.

### Silent failures in SPARQL

Two failure modes cost enough time to be worth recording, because neither produces an error — a whole property simply goes missing from the manifest.

`BIND` inside an `OPTIONAL` cannot see variables bound outside it. An `OPTIONAL` group is evaluated on its own before the left join, so an outer `?manifest` or `?canvas` is unbound within it, `CONCAT` raises a type error on the unbound argument, and every template triple using the result disappears. Any such `BIND` has to re-state the patterns binding the variables it reads.

`CONCAT` also rejects non-string literals. `schema:position` is an `xsd:integer`, so it needs wrapping in `STR()`; passing it raw fails the same silent way.

The general lesson is that when a property is missing from the framed manifest, an unbound `BIND` is a likelier culprit than the framing. It is also worth checking what the triple store actually holds before debugging a query — the store here is grown incrementally, so a query can be correct and still return nothing.
