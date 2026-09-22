# Example queries

Example queries for a knowledge graph version of BHL.


## External linking

Many resources link to BHL, these links are essentially “votes” on the value of BHL content. Hence having these links could be useful in ranking search results in BHL. But they would also enable BHL to be a “hub” where readers of the content are pointed to external resources they might not otherwise be aware of.

## Bibliographic linking

People often cite literature as text strings, such as a full article citation, or a “micro citation” such as journal, volume, page. I want to convert these strings into locations in BHL. There are at least three reasons to do this:

1. It enables us to identify articles in BHL, which can then become recognised as “parts”. This is essentially how BioStor works.
2. It enables us to convert a citation in a taxonomic database to a link to BHL. Classic example is converting a microcitation in IPNI into a BHL page id (see below)
3. BHL is full of implied citation links between articles, for example page 59924336 includes:

> Pleurothallis butcheri L.O.Williams, Fieldiana Bot. 29: 346, 1961.
> Ety.: Named in honor of Henry P. Butcher who collected extensively in Panama for over 50 years.
> Syn.: Acianthera butcheri (L.O.Williams) Pridgeon & M.W.Chase, Lindleyana 16: 242, 2001.

where “Fieldiana Bot. 29: 346, 1961.” is a microcitation that corresponds to page 2452391, which includes the line

> Pleurothallis Butcheri L. Wms., sp. nov.

It would enhance the BHL experience if those two pages, 59924336 and 2452391, where linked.

The tool designed to resolve citations like this is OpenURL. 

### OpenURL query

Here is an example of an OpenURL-style query in SPARQL:

```
PREFIX : <https://schema.org/>
SELECT *
WHERE {
?title :issn "0015-0746" .
?item :isPartOf ?title .
?item :volumeNumber "29" .
?page :isPartOf ?item .
?page :name "Page 346" .
}
LIMIT 10
```

Note that we have “cheated” by mapping “Fieldiana Bot.” to the ISSN 0015-0746, there is no field in BHL that has the string "Fieldiana Bot.”.

This is equivalent to the OpenURL used by IPNI: http://www.biodiversitylibrary.org/openurl?ctx_ver=Z39.88-2004&rft.date=1961&rft.issue=6&rft.spage=346&rft.volume=29&rft_id=http://www.biodiversitylibrary.org/bibliography/42247&rft_val_fmt=info:ofi/fmt:kev:mtx:book&url_ver=z39.88-2004 (this URL is on the web page, it can also be retrived via the API record https://ipni.org/api/1/n/urn:lsid:ipni.org:names:202508-2 as the field `bhlLink`);

Here is the SPARQL for that URL:

```
PREFIX : <https://schema.org/>
SELECT *
WHERE {
?item :isPartOf <https://www.biodiversitylibrary.org/bibliography/42247> .
?item :volumeNumber "29" .
OPTIONAL {
?item :issueNumber "6" .
}
?page :isPartOf ?item .
?page :name "Page 346" .
}
LIMIT 10
```

Note that `issueNumber` has been made optional because I don’t (yet?) include issue as a property of an item.


## Metadata cleaning

These queries are “inside baseball”, but seek to help improve the quality of BHL metadata.

### Creator matching

Author disambiguatiuon is a big task, one approach I have used is maximum weighred cliques, which takes as input all the first names for the same last name (e.g., all the names with the familyName “Roberts”) and then attempts to cluster them. Below is a query to generate that kind of data.

```
PREFIX : <https://schema.org/>

SELECT ?creator ?name
WHERE {
  ?creator :familyName "Roberts" .
  ?creator :givenName ?givenName .

  OPTIONAL {
    ?creator :additionalName ?additionalName .
  }

  BIND(
    CONCAT(
      ?givenName,
      IF(BOUND(?additionalName), CONCAT(" ", ?additionalName), "")
    )
    AS ?name
  )
}
ORDER BY ?givenName ?name
```


### Find all titles and items in a collection of “Monographic series”

Large journals are sometimes divided into thematic series, such that we can have a complex set of overlapping lists of volumes, sometime numbered both w.r.t. to journal volume and the individual series. This query attempts to bring together all volumes across multiple titles, and requires just one initial title as a “seed”. Examples include title 40366 (Monographs in Systematic Botany) and title 2320 (Fieldiana).


```
PREFIX schema: <https://schema.org/>
SELECT ?item ?volume
       (COUNT(DISTINCT ?t) AS ?n_titles)
       (GROUP_CONCAT(DISTINCT ?tid;   separator=", ") AS ?title_ids)
       (GROUP_CONCAT(DISTINCT ?tname; separator=" | ") AS ?title_names)
WHERE {
  VALUES ?seed { <https://www.biodiversitylibrary.org/bibliography/40366> }

  ?seed (^schema:isPartOf/schema:isPartOf)* ?title .
  ?item schema:isPartOf ?title .

  OPTIONAL { ?item schema:name ?volume }

  ?item schema:isPartOf ?t .
  BIND(REPLACE(STR(?t), "^.*/", "") AS ?tid)
  OPTIONAL { ?t schema:name ?tname }
}
GROUP BY ?item ?volume
ORDER BY ?volume
```

Claude commentary on this query

> You were right — 6 of the 41 items are in the cluster but not listed under 40366.

> 325610  v. 13 1985    -> 149829 Index to plant chromosome numbers
> 330456  v. 30 1990    -> 149829
> 325348  v. 40 1991    -> 149829
> 325342  v. 51 1994    -> 149829
> 327794  v. 58 1996    -> 149829
> 301372  v. 64 (1997)  -> 149317 Icones pleurothallidinarum

> Five are volumes of Index to plant chromosome numbers and one of Icones pleurothallidinarum — both monographic series that are otherwise substantially inside 40366 (149829 has 8 items, 3 of them also in 40366; 149317 has 32, 31 of them also in 40366). So these look like gaps in BHL's metadata rather than genuinely separate publications: volumes that belong to the parent series but weren't linked to it. Exactly the case you suspected.

## Annotations

### Points from geotagged text

`annotations/annotate_text.php` runs the coordinate patterns in `geotag.php` over a page's
OCR text and writes each match as a Web Annotation. The body of the annotation is the point locality, represented as an RFC 5870
`geo:` URI, and it carries a `geosparql:asWKT` geometry — which some SPARQL map viewers detect from the `wktLiteral` datatype and plot without further help. The WKT datatype is also supported by GeoSPARQL. The following query finds all point locality annotations for the journal *European Journal of Taxonomy*:

```
PREFIX oa: <http://www.w3.org/ns/oa#>
PREFIX geosparql: <http://www.opengis.net/ont/geosparql#>
PREFIX : <https://schema.org/>
SELECT ?wkt ?page ?name
WHERE {
  VALUES ?title { <https://www.biodiversitylibrary.org/bibliography/144642> } .
  ?item :isPartOf ?title .
  ?page :isPartOf ?item .
  
  ?target oa:hasScope ?page .
  ?annotation oa:hasTarget ?target . 
  ?annotation oa:hasBody ?place .
  ?place geosparql:asWKT ?wkt .
 }
ORDER BY ?page
```

`oa:hasScope` is what carries the BHL page. The selectors point into the OCR text file rather than the page — an offset means nothing until you say what it counts into — so `oa:hasSource` is the `.txt` on S3 and the page hangs off the target separately.

One row per annotation, so a coordinate printed twice on a page gives two rows stacked on one pin. For markers, group on the geometry. The `geo:` URI is written at a fixed precision
precisely so that repeated readings of a place converge on one node, and the WKT is built from the same numbers, so grouping on either is safe. Here is a query from Claude which finds all geotag annotations in the triple store:

```
PREFIX oa: <http://www.w3.org/ns/oa#>
PREFIX geosparql: <http://www.opengis.net/ont/geosparql#>
SELECT ?wkt (COUNT(DISTINCT ?annotation) AS ?mentions) (COUNT(DISTINCT ?page) AS ?pages) (SAMPLE(?label) AS ?text)
WHERE {
  ?annotation a oa:Annotation ; oa:hasBody ?place ; oa:hasTarget ?target .
  ?place geosparql:asWKT ?wkt .
  ?target oa:hasScope ?page ; oa:hasSelector ?quote .
  ?quote a oa:TextQuoteSelector ; oa:exact ?text . # oa:exact is the OCR text verbatim, line breaks and all, because it has to match the # page for the selector to relocate. Flatten it for a map label.
  BIND(REPLACE(?text, "\\s+", " ") AS ?label)
}
GROUP BY ?wkt
ORDER BY DESC(?mentions)
```

Two things to watch when exporting these.

Ask for TSV rather than CSV. `oa:exact` keeps the line break wherever the coordinates were
printed across two lines, and in CSV that is a real newline inside a quoted field — legal,
but it makes a naive line-based reader see more rows than there are. TSV escapes it.

Axis order flips between the body's two forms. The `geo:` URI is *latitude,longitude* per RFC 5870; WKT is x y, so longitude comes first. If you ever want the numbers rather than the geometry, take them from the URI and remember which way round it is:

```
  BIND(STRAFTER(STR(?place), "geo:") AS ?coordinates)
  BIND(xsd:decimal(STRBEFORE(?coordinates, ",")) AS ?latitude)
  BIND(xsd:decimal(STRAFTER(?coordinates, ",")) AS ?longitude)
```

Do not declare `PREFIX geo:` for GeoSPARQL in these queries, common though that convention is — `geo:` is also the scheme of the body URIs here, and the two will not stay distinguishable by eye. Hence `geosparql:` above.


