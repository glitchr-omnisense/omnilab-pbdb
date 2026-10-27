# omnilab/pbdb

The Paleobiology Database's data service 1.2 (`https://paleobiodb.org/data1.2/`) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): fossil and living taxa by number
or name - their first and last appearances in Ma, classification, ecology -, and fossils by
number, as entities; a search of names; outside the contract, the fossils of a place and an age.
No key.

## Installation

```sh
composer require omnilab/pbdb
```

```php
use Omnilab\Pbdb\PbdbSourceFactory;

$pbdb = (new PbdbSourceFactory($httpClient))->create(['mailto' => 'support@glitchr.io']);
```

With the Symfony bundle, the factory is registered as `pbdb` (see the core's docs).

## Credentials

None: PBDB's data service takes no key and no account.

## Options

The factory's options, with the defaults it applies (`PbdbSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `radius_km` | `float` | `10` | no | the circle around a `Point`, in kilometres, for `fossils()` when the query gives no `radius` |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: PBDB publishes no rate |
| `base_uri` | `string` | `https://paleobiodb.org/data1.2/` | no | the API the source calls |

Per call, `fossils()` reads the filters `OccurrenceQuery::TAXON`, `OccurrenceQuery::COUNTRY`,
`PbdbSource::MAX_MA`, `PbdbSource::MIN_MA` and `PbdbSource::INTERVAL`; any other key is refused
before any call ([Fossils](#fossils)).

## Capabilities

`capabilities()` answers `entity`, `search`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a taxon number (`54833`, `pbdb:`, `txn:`, its classic page) or a name (a variant or a synonym gives the taxon it names), or a fossil (`pbdb.occurrence:139292`, `occ:`); another scheme refused before any call |
| `search` | yes | PBDB's autocompletion: the taxa whose name begins with the text, the most recorded first, one page; a domain other than taxonomy answers an empty page |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no | refused before any call: a fossil has an age in millions of years, no time - `fossils()`, outside the contract, lists them |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no | none: PBDB gives no other base's id (Wikidata carries PBDB's taxon number, P10907) |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no | `fossils()` takes a `BBox` (across the antimeridian too) or a `Point` and a radius |
| `station` | no |  |
| `cloud_cover` | no |  |

Outside the contract, on `Omnilab\Pbdb\PbdbSource`: `fossils(OccurrenceQuery $query): Page` -
the fossil occurrences of a place and an age, each with its place and no time
([below](#fossils)).

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

- No other base's id (no GBIF, NCBI or Wikidata). Wikidata carries PBDB's taxon number (P10907:
  23,600 items on 2026-10-16, T. rex among them), which omnilab/wikidata reads as a cross-reference
  and looks items up by: the `Collector` follows a Wikidata item into PBDB, and offers a PBDB taxon's
  number to Wikidata.
- Not read: collections and their own records, measurements, specimens, opinions, intervals and
  their boundaries, strata, the diversity curves; the three-letter vocabulary.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Model\BBox;
use Omnilab\Pbdb\PbdbSource;
use Omnilab\Pbdb\PbdbSourceFactory;
use Omnilab\Source\OccurrenceQuery;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$pbdb = (new PbdbSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org']);

$rex = $pbdb->entity('Tyrannosaurus rex');
echo $rex->label, ' (', $rex->id, '): ', $rex->value('early_interval'), ' to ', $rex->value('late_interval'), ', ', $rex->property('first_appearance_max_ma'), ' to ', $rex->property('last_appearance_min_ma'), ', ', $rex->value('occurrence_count'), " occurrences\n";

// Dinosaurs of 70 to 66 Ma between Montana and Wyoming: outside the contract (an age is no date).
$fossils = $pbdb->fossils(new OccurrenceQuery(new BBox(-112, 44, -104, 49), filters: [OccurrenceQuery::TAXON => 'Dinosauria', PbdbSource::MAX_MA => 70, PbdbSource::MIN_MA => 66], limit: 2));
printf("%d dinosaur occurrences; the first %d:\n", $fossils->total, count($fossils));
foreach ($fossils as $fossil) {
    echo '  ', $fossil->id, '  ', $fossil->label, '  ', $fossil->place, "\n";
}
echo 'autocompletion of "Tyranno": ', count($pbdb->search(new Query('Tyranno', limit: 50))), " taxa\n";
```

```
Tyrannosaurus rex (pbdb:54833): Campanian to Maastrichtian, 83.6 Ma to 66 Ma, 70 occurrences
493 dinosaur occurrences; the first 2:
  pbdb.occurrence:139340  Ankylosaurus magniventris - Hell Creek, Late Maastrichtian  47.637699,-106.569901
  pbdb.occurrence:147092  Pachycephalosaurus wyomingensis - Hell Creek, Maastrichtian  47.695831,-106.227776
autocompletion of "Tyranno": 25 taxa
```

(as answered on 2026-10-27: three calls)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        pbdb: { factory: pbdb, options: { mailto: '%env(OMNILAB_MAILTO)%', radius_km: 10 } }
```

`fossils()` is a method of `PbdbSource`, not of the interface the bundle injects: check the
instance before calling it.

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

PBDB's own: an unknown taxon or occurrence is its 404 (its errors in JSON): null. Before any call
(`NotSupportedException`): an identifier of another scheme; `occurrences()` whatever the query;
in `fossils()`, a period of dates, a sort (PBDB answers 400 to its own age orders), a filter not
read, a taxon of another scheme.

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

Again on 2026-10-27: *Tyrannosaurus rex* by its name, the dinosaurs of 70 to 66 Ma between Montana
and Wyoming (493), the autocompletion of "Tyranno" (25 taxa; the example above).

Not verified: a 429 or an outage (none met).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the Paleobiology Database. Its data are under
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/); PBDB asks that a use of its data say
where it came from and when, and cite the references the records come from, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-pbdb#terms-of-use) says. Whoever runs
the package is responsible for complying with them and for any use made of what it returns: see
the README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-pbdb#responsibility)
section.
