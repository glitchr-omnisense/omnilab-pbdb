# omnilab/pbdb

## Installation

```sh
composer require omnilab/pbdb
```

```php
use Omnilab\Pbdb\PbdbSourceFactory;

$pbdb = (new PbdbSourceFactory($httpClient))->create(['mailto' => 'support@glitchr.io']);
```

With the Symfony bundle, the factory is registered as `pbdb` (see the core's docs).

## Which API

PBDB's data service 1.2, `https://paleobiodb.org/data1.2/`, in its own vocabulary (`vocab=pbdb`:
`taxon_name`, `max_ma`... rather than the three-letter one): `taxa/single.json` (a taxon, with
`show=attr,app,class,parent,common,ecospace,img,ref`), `taxa/auto.json` (autocompletion),
`occs/single.json` and `occs/list.json` (with `show=coords,loc,paleoloc,strat,class,ref` and
`rowcount`). PBDB answers 404 with its errors in JSON for an unknown taxon or occurrence.

## Calls

| Method | Reads | PBDB |
|---|---|---|
| `entity()` | a taxon number: `54833`, `pbdb:54833`, `txn:54833`, `https://paleobiodb.org/classic/basicTaxonInfo?taxon_no=54833` | `taxa/single.json?id=` |
| | a name; a variant or a synonym gives the taxon it names, the name among its synonyms (`Felis leo`: *Panthera leo*, two calls) | `taxa/single.json?name=` |
| | a fossil: `pbdb.occurrence:139292`, `occ:139292` | `occs/single.json?id=occ:` |
| `search()` | `Query::$text`: PBDB's autocompletion, the taxa whose name begins with it, the most recorded first, one page (no cursor); a domain other than taxonomy answers nothing | `taxa/auto.json?name=&limit=` |
| `fossils()` | an `OccurrenceQuery` (below), outside the contract | `occs/list.json?...&rowcount` |
| `occurrences()` | refused: a fossil has no time | - |

Why autocompletion: PBDB's wildcard list (`taxa/list.json?match_name=Tyranno%`) answered three
names on 2026-10-16, *Tyrannosaurus* not among them, and its `name=Tyranno%` one. The
autocompletion gives the vernacular form of a name too (`tyrannosaurid` for Tyrannosauridae): one
entity a taxon is kept, under its scientific name.

## What a taxon holds

`id` (`pbdb:` its number - the taxon's original number, which every variant shares: *Panthera leo*
is 46521), `label`, `domain` taxonomy, `kind` (its rank), `synonyms` (its common name; the name it
was found by), `url` (its classic page), `image` (its silhouette, PhyloPic's, served by PBDB), and:

| Key | PBDB | Value |
|---|---|---|
| `taxon_name`, `taxon_authority`, `taxon_rank` | taxon_name, taxon_attr, taxon_rank | text |
| `accepted_name`, `difference` | accepted_name when another; how the name differs (`recombined as`) | text |
| `common_name` | common_name | text |
| `extant` | is_extant | boolean |
| `occurrence_count` | n_occs | number |
| `early_interval`, `late_interval` | the intervals of its first and last appearances | text |
| `first_appearance_max_ma`, `first_appearance_min_ma`, `last_appearance_max_ma`, `last_appearance_min_ma` | firstapp_max_ma... | Quantity, Ma |
| `phylum`, `class`, `order`, `family`, `genus` | the classification (PBDB's `NO_ORDER_SPECIFIED` left out) | text |
| `environment`, `motility`, `life_habit`, `diet`, `reproduction`, `ontogeny` | the ecospace | text |
| `primary_reference` | its first reference | text |

`related`: `parent`, `accepted` (when another taxon). No identifier of another base.

## Fossils

| `OccurrenceQuery` | PBDB |
|---|---|
| `place`: a `BBox` | `lngmin`, `lngmax`, `latmin`, `latmax` (a west past the east, across the antimeridian, as PBDB reads it: 272 mammals between 170° and -170°, 50° S to 30° S) |
| `place`: a `Point` and `radius` | `loc=POLYGON((...))`, the circle as 64 sides; `radius_km` (10) when the query gives none |
| `from`, `to` | refused: an age is in millions of years |
| `OccurrenceQuery::TAXON` | a number or an `Identifier` of `pbdb`: `base_id=txn:` (its subordinate taxa included); a name: `base_name` |
| `OccurrenceQuery::COUNTRY` | `cc`, ISO 3166-1 alpha-2, or a list |
| `PbdbSource::MAX_MA`, `MIN_MA` (`max_ma`, `min_ma`) | `max_ma`, `min_ma`: PBDB's default rule, the occurrences whose age overlaps the span |
| `PbdbSource::INTERVAL` (`interval`) | `interval`: `Maastrichtian`, `Jurassic` |
| `sort` | refused: PBDB answered 400 to its own `max_ma` and `min_ma` orders on 2026-10-16 ("Bad value for parameter 'order'"), though it lists them |
| `limit`, `cursor` | `limit` (5,000 at most), `offset`; the total is PBDB's `records_found` |

A fossil holds: `id` (`pbdb.occurrence:139292`), `label` (its taxon, formation, interval), `kind`
`fossil occurrence`, `place` (where found), no `time`, `url` (its API address), `related`
(`taxon`: its accepted taxon), and:

| Key | PBDB | Value |
|---|---|---|
| `taxon_name`, `taxon_rank`, `identified_name` | accepted_name, accepted_rank, identified_name | text |
| `early_interval`, `late_interval` | the intervals | text |
| `max_ma`, `min_ma` | its age's bounds | Quantity, Ma |
| `phylum` ... `genus` | its classification | text |
| `collection`, `formation`, `geological_group`, `member` | collection_no, the stratigraphy | text |
| `country`, `state`, `coordinate_precision` | cc, state, latlng_precision | text |
| `paleo_latitude`, `paleo_longitude`, `paleo_model` | where it was at the time (GPlates) | number, text |
| `primary_reference` | its reference | text |

## What PBDB does not give here

- No other base's id (no GBIF, NCBI or Wikidata); Wikidata carries PBDB's taxon number (P10907: 23,600 items on 2026-10-16, T. rex among them), which omnilab/wikidata does not read yet.
- Not read: collections and their own records, measurements, specimens, opinions, intervals and
  their boundaries, strata, the diversity curves; the three-letter vocabulary.

## Verified, and not

Against the real service on 2026-10-16, without a key: *Tyrannosaurus rex* by its number, its name
and `txn:` (Campanian to Maastrichtian, 83.6 to 66 Ma, 70 occurrences, a carnivore, its picture);
*Felis leo* to *Panthera leo* (extant); unknown numbers and names (404); a fossil of T. rex in the
Scollard Formation (occ:139292, 72.2 to 66 Ma, 64.06° N at the time); the 1,282 dinosaurs of 70 to
66 Ma between Montana and Wyoming, two pages of 50, all in the box and overlapping the span; ten
T. rex within 100 km of (47.6, -106.9); 74 *Tyrannosaurus* in the US in the Maastrichtian; the
autocompletion of "Tyranno" (18 taxa). By curl that day: a box across the antimeridian, the 400 of
the age orders, the three names of `match_name`. The fixtures in `Tests/Fixtures/` are its answers
(`index.json` names each one's address).

Not verified: a 429 or an outage (none met).
