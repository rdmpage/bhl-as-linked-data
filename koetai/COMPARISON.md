# Two RDF mappings of BHL, compared

Two independent conversions of the Biodiversity Heritage Library data exist:

- **schema.org** — this repository. PHP reading BHL's SQL dump, emitting N-Triples. Adds IIIF manifests, page images and OCR on top of the catalogue.
- **dcterms/bibo** — the [koetai-platform](https://github.com/) BHL pipeline. A YARRRML/RML mapping run by Morph-KGC directly over BHL's TSV exports, validated with ShEx, indexed in QLever, with Wikidata reconciliation.

They were written for different purposes and are largely complementary. This note records where they agree, where they differ, and the handful of places where a difference would silently break a query across both.

## The important thing: subject URIs are identical

Both mint `https://www.biodiversitylibrary.org/{type}/{id}`:

```
https://www.biodiversitylibrary.org/bibliography/8942
https://www.biodiversitylibrary.org/item/39504
https://www.biodiversitylibrary.org/page/53132191
https://www.biodiversitylibrary.org/part/229237
https://www.biodiversitylibrary.org/creator/216568
```

So the two graphs can be loaded together and joined on subject without reconciliation. Everything below is vocabulary, not identity.

## Vocabularies

| | schema.org version | dcterms/bibo version |
|---|---|---|
| core | schema.org | dcterms, bibo, foaf |
| custom terms | none | `bhlv:` = `https://www.biodiversitylibrary.org/vocab/` |
| also used | IIIF Presentation 3, exif, Web Annotation (oa), dc 1.1 | dwc, owl |

Neither is "more correct". schema.org keeps everything in one widely-understood vocabulary at the cost of some awkward fits; dcterms/bibo uses established bibliographic terms and invents `bhlv:` where nothing fits, which is more honest about the gaps but needs its own documentation.

## Titles

| fact | schema.org | dcterms/bibo |
|---|---|---|
| type | `schema:CreativeWork`, `schema:DataFeed` | `bhlv:Title` |
| title | `schema:name` | `dcterms:title` |
| short title | `schema:alternateName` | — |
| language | `schema:inLanguage` `"en"` | `dcterms:language` `"ENG"` |
| subjects | `schema:keywords` | `dcterms:subject` |
| creators | `schema:creator` / `schema:contributor` | `dcterms:creator` |
| identifiers | `schema:sameAs` to a resolved URI; `schema:issn` | `dcterms:identifier` CURIE literal, plus `owl:sameAs` from a post-processing pass |
| dates | — | `dcterms:issued` (StartYear) + `bhlv:endYear` |
| member items | `schema:dataFeedElement` → ordered list elements | — |

Two differences worth noting.

**Creator role.** BHL's `CreatorType` distinguishes MARC main entry from added entry. The schema.org version splits these into `schema:creator` and `schema:contributor`; the dcterms version maps both to `dcterms:creator` and keeps the raw string in `bhlv:creatorType`, so the distinction is preserved but not actionable. Worth knowing that the entry type is a property of the *credit*, not the person — 10,007 creators are a main entry on one title and an added entry on another, so neither mapping can put a single role on the creator resource.

**Title dates.** The dcterms version carries `dcterms:issued` and `bhlv:endYear` from the title table's StartYear/EndYear; the schema.org version currently has no title-level dates at all. That is a gap on the schema.org side.

## Items

| fact | schema.org | dcterms/bibo |
|---|---|---|
| type | `CreativeWork`, `DataFeed`, `PublicationVolume` | `bibo:Book` |
| in title | `schema:isPartOf` (multi-valued) | `dcterms:isPartOf` |
| date | `schema:startDate` + `schema:endDate` + `schema:datePublished` | `dcterms:date` |
| volume | `schema:volumeNumber` (parsed, multi-valued) | — |
| barcode | `schema:sameAs` → `archive.org/details/{barcode}` | `dcterms:identifier` literal |
| institution | `schema:provider` | `bhlv:institution` |
| rights | `schema:copyrightNotice` | `bhlv:copyrightStatus` |
| pages | `schema:dataFeedElement` → ordered list | — (pages point up) |
| IIIF manifest | `schema:encoding` | — |

**Dates are the substantive difference.** An item is a physical bound volume whose contents may have been published across several years: of items with dated parts, 21% span more than one year. `item.Year` is a single number and cannot express that.

The schema.org version parses `VolumeInfo` with [bhl-volume-parser](https://github.com/rdmpage/bhl-volume-parser) (98.74% of 109,844 distinct strings parse) and emits `startDate`/`endDate` as `xsd:gYear`, always both, falling back to `item.Year` as a degenerate range where the volume string yields no date. `datePublished` stays a straight read of `item.Year` specifically so it agrees with a direct table-to-RDF mapping like this one. Coverage is 93.5% with both endpoints.

That parsing also yields `volumeNumber`, correctly handling forms that defeat naive splitting:

```
v.3 1862-63                      ->  1862..1863        two-digit end year expanded
v.16=[1884-1900:I-MARBUT] (1918) ->  1918              bracketed span is coverage, not publication
pt.1-6 (1833-1838)               ->  volumes 1..6, 1833..1838
```

## Pages

| fact | schema.org | dcterms/bibo |
|---|---|---|
| type | — | `bibo:Page` |
| in item | `schema:isPartOf` | `dcterms:isPartOf` |
| sequence | on the list element, `schema:position` | `bhlv:sequenceOrder` on the page |
| label | `schema:name` `"Page 393"` | `bhlv:pagePrefix` + `bhlv:pageNumber` |
| image, thumbnail | `schema:image`, `schema:thumbnailUrl` | — |
| OCR text | `schema:encoding` → S3 text file | — |
| IIIF canvas | `schema:sameAs` | — |
| **scientific names** | — | **`dwc:scientificName`, `bhlv:nameBankID`** |

This is where the two diverge most, and in opposite directions. The schema.org version carries everything needed to build a IIIF manifest — canvas identity, image URLs, dimensions, OCR — and no taxonomic names. The dcterms version carries `dwc:scientificName` from BHL's `pagename` table with Wikidata reconciliation, and nothing about images.

**Page ordering is a real modelling disagreement.** The schema.org version routes every page through a `DataFeedItem` list element, costing three triples per page to express order; the dcterms version puts `bhlv:sequenceOrder` directly on the page, costing one. The indirection exists so that position belongs to the membership rather than the page, which matters when a page belongs to several lists at different positions. For item→page it never does: **zero** pages belong to more than one item, and zero have more than one `SequenceOrder`. So for items the dcterms approach is right and the schema.org one is paying ~137M triples across full BHL for nothing. For part→page the indirection is necessary — 111,975 pages appear in more than one part, 95,840 at different positions — and both mappings do use an intermediate resource there.

## Parts

| fact | schema.org | dcterms/bibo |
|---|---|---|
| type | `ScholarlyArticle`, `DataFeed` | `bibo:Article` |
| title | `schema:name` | `dcterms:title` |
| in item | `schema:isPartOf` | `dcterms:isPartOf` |
| date | `schema:datePublished` | `dcterms:date` |
| volume, issue | — | `bibo:volume`, `bibo:issue` |
| pagination | `schema:pagination` | `bhlv:pageRange` |
| pages | `schema:dataFeedElement` ordered list | `bhlv:hasPage` + `bhlv:PagePosition` resource |
| DOI | `schema:sameAs` | `owl:sameAs` |
| PDF | `schema:encoding` | — |

## Three things that would break a cross-graph query

These are the cases where the two graphs say the same thing in ways that will not join.

**1. The same identifier resolves to different URIs.**

| namespace | schema.org | dcterms/bibo |
|---|---|---|
| Wikidata | `http://www.wikidata.org/entity/{}` | `http://www.wikidata.org/entity/{}` — agree |
| OCLC | `https://www.worldcat.org/oclc/{}` | `http://www.worldcat.org/oclc/{}` |
| DLC | `https://lccn.loc.gov/{}` | `http://id.loc.gov/authorities/names/{}` |

OCLC differs only in scheme, which in RDF makes them different resources; DLC points into two genuinely different namespaces. Only Wikidata agrees exactly. Each side also covers namespaces the other does not: ISSN on one, VIAF and SNAC ARK on the other.

**2. Language codes.** BHL stores MARC / ISO 639-2/B codes (`ENG`, `FRE`, `GER`). The dcterms version passes these through to `dcterms:language`; the schema.org version maps them to BCP 47 for `schema:inLanguage` (`en`, `fr`, `de`). These are not stylistic variants: where a language has an ISO 639-1 two-letter code, that code *is* the subtag and the three-letter form is absent from the IANA registry, so `"eng"` is invalid rather than merely discouraged. The mapping is not simply "take the first two letters" — six of BHL's 54 codes have no two-letter equivalent (`und`, `mul`, `frm`, `ota`, `grc`, `gmh`) and are already valid as they stand, and two (`scr`, `scc`) were withdrawn from ISO 639-2 in 2008 and are absent from the current table altogether.

**3. Date literals.** The dcterms pipeline's README records as a known limitation that *"some `dcterms:date` values are year ranges typed `xsd:gYear`"*. The schema.org version hit the same bug and now refuses them: `create_date()` accepts only `YYYY`, `YYYY-MM` or `YYYY-MM-DD` and emits nothing otherwise. The failure mode is worth describing because it is silent — `"1893-1908"` typed as `gYear` or `gYearMonth` is outside that datatype's lexical space, so it is an ill-typed literal: it raises no error and simply fails every comparison, dropping the row from range filters and aggregates with no indication. Across BHL's 404,792 dated parts, 1,584 carry a year range.

## A caution about part languages

`part.LanguageName` looks like free article-level metadata. It is not: it equals the parent title's language for **98.7%** of 370,283 part/title pairs, and is inherited rather than observed. Part 8000 is titled *"Preliminary Note on a new genus of Earthworms"* and is recorded as German, because *Zoologischer Anzeiger* is coded `GER`. Neither mapping currently emits it, which is the right call.

## Summary

The two are more complementary than competing. The dcterms/bibo mapping is a faithful, validated, reproducible rendering of the BHL dump with taxonomic names and Wikidata links. The schema.org mapping adds what BHL's tables do not directly contain — parsed volumes and date ranges, IIIF manifests, page images, OCR, canvas identity — at the cost of a heavier ordering model and some vocabulary fits that need explaining.

Since the subject URIs match, the practical question is not which to choose but whether the small number of genuine conflicts above are worth aligning so that both can be loaded into one store.
