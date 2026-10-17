# omnilab/pbdb

The Paleobiology Database for [omniscient/omnilab](https://github.com/glitchr-studio/omnilab): a taxon,
fossil or living, by its number or its name - its rank, accepted name, common name, whether it is
extant, its first and last appearances in the fossil record (their intervals and their ages in
millions of years), how many occurrences PBDB holds, its classification, its ecology (environment,
motility, life habit, diet), its first reference, its picture; a fossil occurrence by its number;
a search of names; and the fossils of a place and an age. No key.

```php
$pbdb = (new PbdbSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$rex = $pbdb->entity('54833');                    // or 'pbdb:54833', 'txn:54833', its page, 'Tyrannosaurus rex'
$rex->value('early_interval');                    // Campanian
$rex->value('last_appearance_min_ma');            // 66 Ma: a Quantity
$rex->value('diet');                              // carnivore

// The dinosaurs of 70 to 66 Ma over Montana and Wyoming: outside the contract.
$page = $pbdb->fossils(new OccurrenceQuery(new BBox(-110, 40, -100, 50),
    filters: [OccurrenceQuery::TAXON => 'Dinosauria', PbdbSource::MAX_MA => 70, PbdbSource::MIN_MA => 66]));
$page->total;                                     // 1282 (on 2026-10-16)
$page->first()->place;                            // where it was found
$page->first()->time;                             // null: an age is no date
$page->first()->value('max_ma');                  // 72.2 Ma
```

```yaml
omnilab:
    sources:
        pbdb:
            factory: pbdb
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                radius_km: 10                  # the circle around a Point, for fossils()
```

## An age is no date: fossils(), outside the contract

The core's fourth shape, `occurrences()`, answers entities each with its **time** - an instant -
and its place. A fossil's age is a span of geological time (72.2 to 66 million years ago, the
Late Maastrichtian), which no `DateTimeImmutable` holds. So this source does not offer
`occurrences()` (refused) and gives the same records through **`fossils(OccurrenceQuery)`**: the
same query object for the place (a `BBox` or a `Point` and its radius), the filters, the page size
and the cursor; a period (`from`, `to`) refused; each entity with its place (where it was found
today) and **no time**, its age in properties (`max_ma`, `min_ma` in `Ma`, `early_interval`,
`late_interval`) and its place at the time (`paleo_latitude`, `paleo_longitude`). Its filters:
`OccurrenceQuery::TAXON`, `COUNTRY`, and its own `PbdbSource::MAX_MA`, `MIN_MA`, `INTERVAL`.

PBDB gives no other base's id; a taxon is named in the core's `pbdb` scheme, a fossil in
`pbdb.occurrence`. `omnilab/col` reads a `pbdb:` id when the Catalogue of Life names one for a taxon (none met on 2026-10-16).

## Terms of use

The Paleobiology Database's data are under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
PBDB asks that a use of its data say where it came from and when ("Data were downloaded from the
Paleobiology Database on [date], using the following parameters: ..."), and cite the references
the records come from (`primary_reference`). The data service needs no key and publishes no rate
([paleobiodb.org/data1.2](https://paleobiodb.org/data1.2/),
[paleobiodb.org/#/faq/citations](https://paleobiodb.org/#/faq/citations)).

See [docs/](docs/index.md) for what is read, how it maps, and what PBDB does not give here.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the Paleobiology Database. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
