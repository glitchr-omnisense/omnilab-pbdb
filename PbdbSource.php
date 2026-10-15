<?php

namespace Omnilab\Pbdb;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\BBox;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Point;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\OccurrenceQuery;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Paleobiology Database, through its data service 1.2
 * (paleobiodb.org/data1.2): a taxon, fossil or living, by its number or its
 * name - its rank, accepted name, common name, whether it is extant, its
 * first and last appearances in the record (their intervals and their ages
 * in millions of years), its occurrences counted, its classification, its
 * ecology (environment, motility, life habit, diet), its first reference;
 * a fossil occurrence by its number; a search of names; and the fossil
 * occurrences of a place and an age (fossils()).
 *
 * A fossil's age is a span of geological time (72.2 to 66 Ma), not a date:
 * fossils() stays outside the contract. It takes an OccurrenceQuery for its
 * place, filters, limit and cursor, refuses a period (from, to), and its
 * entities have a place and no time - the age is in properties (max_ma,
 * min_ma, early_interval, late_interval). occurrences() is not offered: its
 * entities must have a time.
 *
 * No key, no published rate.
 */
final class PbdbSource extends HttpSource
{
    public const BASE_URI = 'https://paleobiodb.org/data1.2/';

    /** The most occurrences a page of fossils() holds. */
    public const MAX_PER_PAGE = 5000;

    /** fossils()' own filters: the oldest and the youngest age, in millions of years; a named interval (Maastrichtian, Jurassic). */
    public const MAX_MA = 'max_ma';
    public const MIN_MA = 'min_ma';
    public const INTERVAL = 'interval';

    private const MAIN_RANKS = ['phylum', 'class', 'order', 'family', 'genus'];

    /** What a taxon is asked for (show=). */
    private const TAXON_BLOCKS = 'attr,app,class,parent,common,ecospace,img,ref';

    /** What an occurrence is asked for (show=). */
    private const OCCURRENCE_BLOCKS = 'coords,loc,paleoloc,strat,class,ref';

    /** @param float $radius the circle around a Point, in kilometres, for fossils() when the query gives none */
    public function __construct(
        HttpClientInterface $http,
        private readonly float $radius = 10.0,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'pbdb';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH];
    }

    /**
     * A taxon by its number (54833, pbdb:54833, txn:54833, its page) or its
     * name (a variant - Felis leo, recombined - gives its taxon, the name
     * among its synonyms); a fossil occurrence by its number
     * (pbdb.occurrence:139292, occ:139292). One call, two for a variant.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $schemes = [Scheme::PBDB, Scheme::PBDB_OCCURRENCE];
        if ($id instanceof Identifier) {
            $identifier = \in_array($id->scheme, $schemes, true) ? $id : throw NotSupportedException::identifier('pbdb', (string) $id);
        } else {
            $text = trim($id);
            $identifier = Identifier::tryOf(Scheme::PBDB, $text) ?? Identifier::parse($text);
            if (null !== $identifier && !\in_array($identifier->scheme, $schemes, true)) {
                throw NotSupportedException::identifier('pbdb', $id);
            }
            if (null === $identifier) {
                return '' === $text ? null : $this->byName($text);
            }
        }
        if (Scheme::PBDB_OCCURRENCE === $identifier->scheme) {
            $record = $this->getJson('occs/single.json', ['id' => 'occ:'.$identifier->value, 'vocab' => 'pbdb', 'show' => self::OCCURRENCE_BLOCKS])['records'][0] ?? null;

            return \is_array($record) && isset($record['occurrence_no']) ? $this->toOccurrence($record) : null;
        }
        $taxon = $this->getJson('taxa/single.json', ['id' => $identifier->value, 'vocab' => 'pbdb', 'show' => self::TAXON_BLOCKS])['records'][0] ?? null;

        return \is_array($taxon) && isset($taxon['orig_no']) ? $this->toTaxon($taxon) : null;
    }

    /**
     * PBDB's autocompletion of taxon names (taxa/auto): the taxa whose name
     * begins with the text, the most recorded first, one page (PBDB's
     * autocompletion has no offset; its wildcard list, taxa/list
     * match_name, answered 3 names for "Tyranno%" on 2026-10-16, leaving
     * Tyrannosaurus out). A domain other than taxonomy answers nothing.
     */
    public function search(Query $query): Page
    {
        $text = trim($query->text);
        if ((null !== $query->domain && Domain::TAXONOMY !== $query->domain) || '' === $text || null !== $query->cursor) {
            return new Page();
        }
        $size = max(1, min(self::MAX_PER_PAGE, $query->limit));
        $entities = [];
        foreach ($this->getJson('taxa/auto.json', ['name' => $text, 'limit' => $size, 'vocab' => 'pbdb'])['records'] ?? [] as $r) {
            if (\is_array($r) && isset($r['taxon_no'], $r['taxon_name']) && 'txn' === ($r['record_type'] ?? 'txn')) {
                // One entity a taxon: its accepted name, not the vernacular one PBDB also matches ("tyrannosaurid").
                $entities[(string) $r['taxon_no']] ??= $this->toTaxon(['orig_no' => $r['taxon_no'], 'taxon_name' => $r['taxon_name'], 'taxon_rank' => $r['taxon_rank'] ?? null, 'n_occs' => $r['n_occs'] ?? null]);
                if (preg_match('~^[A-Z]~', (string) $r['taxon_name']) && !preg_match('~^[A-Z]~', $entities[(string) $r['taxon_no']]->label)) {
                    $entities[(string) $r['taxon_no']] = $this->toTaxon(['orig_no' => $r['taxon_no'], 'taxon_name' => $r['taxon_name'], 'taxon_rank' => $r['taxon_rank'] ?? null, 'n_occs' => $r['n_occs'] ?? null]);
                }
            }
        }

        return new Page(array_values($entities));
    }

    /**
     * The fossil occurrences of a place and an age, outside the contract (a
     * fossil's age is no date): each an entity with its place (where it was
     * found today; its place then in properties) and no time.
     *
     * A BBox is the box (across the antimeridian, west past east, as PBDB
     * reads it); a Point, the circle of the query's radius (10 km by
     * default) as a polygon of 64 sides; no place, the world. A period
     * (from, to) is refused. Filters: OccurrenceQuery::TAXON (a number, an
     * Identifier of pbdb, or a name: its subordinate taxa included),
     * COUNTRY (ISO 3166-1 alpha-2, or a list), max_ma and min_ma (millions of
     * years, PBDB's overlap rule), interval (a named interval: Maastrichtian,
     * Jurassic). No sort: PBDB's own order (it answers 400 to its age orders
     * on 2026-10-16). Page by offset, its total.
     *
     * @return Page<Entity>
     */
    public function fossils(OccurrenceQuery $query): Page
    {
        $filters = $this->filters($query, OccurrenceQuery::TAXON, OccurrenceQuery::COUNTRY, self::MAX_MA, self::MIN_MA, self::INTERVAL);
        if (null !== $query->from || null !== $query->to) {
            throw NotSupportedException::operation('pbdb', 'take a period of dates: a fossil\'s age is in millions of years (filter by max_ma, min_ma or interval)');
        }
        if (null !== $query->sort) {
            throw NotSupportedException::operation('pbdb', \sprintf('sort fossils by "%s" (PBDB answers 400 to its age orders: its own order only)', $query->sort));
        }
        $size = max(1, min(self::MAX_PER_PAGE, $query->limit));
        $offset = max(0, (int) $query->cursor);
        $parameters = $this->taxonFilter($filters[OccurrenceQuery::TAXON] ?? null) + $this->area($query) + [
            'cc' => isset($filters[OccurrenceQuery::COUNTRY]) ? strtoupper(implode(',', (array) $filters[OccurrenceQuery::COUNTRY])) : null,
            'max_ma' => isset($filters[self::MAX_MA]) ? (string) (float) $filters[self::MAX_MA] : null,
            'min_ma' => isset($filters[self::MIN_MA]) ? (string) (float) $filters[self::MIN_MA] : null,
            'interval' => isset($filters[self::INTERVAL]) ? (string) $filters[self::INTERVAL] : null,
            'vocab' => 'pbdb',
            'show' => self::OCCURRENCE_BLOCKS,
            'limit' => $size,
            'offset' => $offset ?: null,
            'rowcount' => 'true',
        ];
        $data = $this->getJson('occs/list.json', $parameters) ?? [];
        $entities = array_values(array_filter(array_map($this->toOccurrence(...), array_values(array_filter($data['records'] ?? [], static fn (mixed $r) => \is_array($r) && isset($r['occurrence_no'])))), static fn (Entity $e) => null !== $e->place));
        $total = isset($data['records_found']) ? (int) $data['records_found'] : null;

        return new Page($entities, null !== $total && $offset + $size < $total ? (string) ($offset + $size) : null, $total);
    }

    private function byName(string $name): ?Entity
    {
        $taxon = $this->getJson('taxa/single.json', ['name' => $name, 'vocab' => 'pbdb', 'show' => self::TAXON_BLOCKS])['records'][0] ?? null;
        if (!\is_array($taxon) || !isset($taxon['orig_no'])) {
            return null;
        }
        $accepted = (string) ($taxon['accepted_no'] ?? $taxon['orig_no']);
        if ((string) ($taxon['taxon_name'] ?? '') === (string) ($taxon['accepted_name'] ?? $taxon['taxon_name'] ?? '')) {
            return $this->toTaxon($taxon);
        }
        // A variant or a synonym: the taxon it names, the name among its synonyms.
        $entity = $this->entity(Identifier::of(Scheme::PBDB, $accepted));

        return null === $entity || \in_array($name, $entity->synonyms, true) ? $entity : $entity->with(['synonyms' => [...$entity->synonyms, $name]]);
    }

    /** @return array<string, mixed> */
    private function taxonFilter(mixed $taxon): array
    {
        if (null === $taxon) {
            return [];
        }
        if ($taxon instanceof Identifier) {
            return Scheme::PBDB === $taxon->scheme ? ['base_id' => 'txn:'.$taxon->value] : throw NotSupportedException::identifier('pbdb', (string) $taxon, 'filter fossils by');
        }
        $text = trim((string) $taxon);
        $identifier = Identifier::tryOf(Scheme::PBDB, $text) ?? Identifier::parse($text);
        if (null !== $identifier && Scheme::PBDB !== $identifier->scheme) {
            throw NotSupportedException::identifier('pbdb', $text, 'filter fossils by');
        }

        return null === $identifier ? ['base_name' => $text] : ['base_id' => 'txn:'.$identifier->value];
    }

    /** @return array<string, string|float> */
    private function area(OccurrenceQuery $query): array
    {
        $place = $query->place;
        if ($place instanceof BBox) {
            return ['lngmin' => $place->west, 'lngmax' => $place->east, 'latmin' => $place->south, 'latmax' => $place->north];
        }
        if (!$place instanceof Point) {
            return [];
        }
        $radius = $query->radius ?? $this->radius;
        $lat = deg2rad($place->latitude);
        $lon = deg2rad($place->longitude);
        $d = $radius / 6371.0088;
        $ring = [];
        for ($i = 0; $i < 64; ++$i) {
            $bearing = 2 * M_PI * $i / 64;
            $lat2 = asin(sin($lat) * cos($d) + cos($lat) * sin($d) * cos($bearing));
            $lon2 = $lon + atan2(sin($bearing) * sin($d) * cos($lat), cos($d) - sin($lat) * sin($lat2));
            $ring[] = self::number(rad2deg($lon2)).' '.self::number(rad2deg($lat2));
        }
        $ring[] = $ring[0];

        return ['loc' => 'POLYGON(('.implode(',', $ring).'))'];
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(\sprintf('%.6F', $value), '0'), '.');
    }

    private static function ma(mixed $value): ?Quantity
    {
        return is_numeric($value) ? new Quantity(0 + $value, 'Ma') : null;
    }

    /** @param array<string, mixed> $t a taxon of PBDB (vocab=pbdb) */
    private function toTaxon(array $t): Entity
    {
        $id = (string) $t['orig_no'];
        $name = self::text($t['taxon_name'] ?? null) ?? $id;
        $rank = self::text(\is_string($t['taxon_rank'] ?? null) ? $t['taxon_rank'] : ($t['accepted_rank'] ?? null));
        $properties = [];
        $add = static function (string $key, string $label, Quantity|string|int|bool|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'pbdb');
            }
        };
        $authority = self::text($t['taxon_attr'] ?? null);
        $add('taxon_name', 'Taxon name', $name);
        $add('taxon_authority', 'Authority', $authority);
        $add('taxon_rank', 'Taxon rank', $rank);
        $accepted = self::text($t['accepted_name'] ?? null);
        $add('accepted_name', 'Accepted name', null !== $accepted && $accepted !== $name ? $accepted : null);
        $add('difference', 'How it differs from the accepted name', self::text($t['difference'] ?? null));
        $add('common_name', 'Common name', self::text($t['common_name'] ?? null));
        $add('extant', 'Extant', isset($t['is_extant']) ? 'extant' === $t['is_extant'] : null);
        $add('occurrence_count', 'Occurrences (in PBDB)', is_numeric($t['n_occs'] ?? null) ? (int) $t['n_occs'] : null);
        $add('early_interval', 'First appearance (interval)', self::text($t['early_interval'] ?? null));
        $add('late_interval', 'Last appearance (interval)', self::text($t['late_interval'] ?? null));
        $add('first_appearance_max_ma', 'First appearance, oldest bound', self::ma($t['firstapp_max_ma'] ?? null));
        $add('first_appearance_min_ma', 'First appearance, youngest bound', self::ma($t['firstapp_min_ma'] ?? null));
        $add('last_appearance_max_ma', 'Last appearance, oldest bound', self::ma($t['lastapp_max_ma'] ?? null));
        $add('last_appearance_min_ma', 'Last appearance, youngest bound', self::ma($t['lastapp_min_ma'] ?? null));
        foreach (self::MAIN_RANKS as $level) {
            $value = self::text($t[$level] ?? null);
            $add($level, ucfirst($level), null !== $value && !str_starts_with($value, 'NO_') ? $value : null);
        }
        foreach (['taxon_environment' => ['environment', 'Environment'], 'motility' => ['motility', 'Motility'], 'life_habit' => ['life_habit', 'Life habit'], 'diet' => ['diet', 'Diet'], 'reproduction' => ['reproduction', 'Reproduction'], 'ontogeny' => ['ontogeny', 'Ontogeny']] as $field => [$key, $label]) {
            $add($key, $label, self::text($t[$field] ?? null));
        }
        $add('primary_reference', 'Primary reference', self::text($t['primary_reference'] ?? null));
        $acceptedNo = isset($t['accepted_no']) && (string) $t['accepted_no'] !== $id ? Identifier::tryOf(Scheme::PBDB, $t['accepted_no']) : null;

        return new Entity(
            id: Identifier::of(Scheme::PBDB, $id),
            label: $name,
            domain: Domain::TAXONOMY,
            kind: $rank,
            synonyms: null !== ($common = self::text($t['common_name'] ?? null)) ? [$common] : [],
            properties: $properties,
            url: 'https://paleobiodb.org/classic/basicTaxonInfo?taxon_no='.$id,
            image: isset($t['image_no']) && is_numeric($t['image_no']) ? 'https://paleobiodb.org/data1.2/taxa/thumb.png?id='.$t['image_no'] : null,
            source: 'pbdb',
            related: array_filter([
                'parent' => Identifiers::of(null !== $acceptedNo ? null : Identifier::tryOf(Scheme::PBDB, $t['parent_no'] ?? null)),
                'accepted' => Identifiers::of($acceptedNo),
            ], static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }

    /** @param array<string, mixed> $o an occurrence of PBDB (vocab=pbdb) */
    private function toOccurrence(array $o): Entity
    {
        $id = (string) $o['occurrence_no'];
        $name = self::text($o['accepted_name'] ?? null) ?? self::text($o['identified_name'] ?? null) ?? 'Unknown';
        $properties = [];
        $add = static function (string $key, string $label, Quantity|string|int|float|bool|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'pbdb');
            }
        };
        $add('taxon_name', 'Taxon name', $name);
        $add('taxon_rank', 'Taxon rank', self::text($o['accepted_rank'] ?? null));
        $add('identified_name', 'Identified as', self::text($o['identified_name'] ?? null));
        $add('early_interval', 'Interval (oldest)', self::text($o['early_interval'] ?? null));
        $add('late_interval', 'Interval (youngest)', self::text($o['late_interval'] ?? null));
        $add('max_ma', 'Age, oldest bound', self::ma($o['max_ma'] ?? null));
        $add('min_ma', 'Age, youngest bound', self::ma($o['min_ma'] ?? null));
        foreach (self::MAIN_RANKS as $level) {
            $value = self::text($o[$level] ?? null);
            $add($level, ucfirst($level), null !== $value && !str_starts_with($value, 'NO_') ? $value : null);
        }
        $add('collection', 'Collection (PBDB)', self::text($o['collection_no'] ?? null));
        $add('formation', 'Formation', self::text($o['formation'] ?? null));
        $add('geological_group', 'Group', self::text($o['geological_group'] ?? null));
        $add('member', 'Member', self::text($o['member'] ?? null));
        $add('country', 'Country', self::text($o['cc'] ?? null));
        $add('state', 'State or province', self::text($o['state'] ?? null));
        $add('coordinate_precision', 'Coordinate precision', self::text($o['latlng_precision'] ?? null));
        $add('paleo_latitude', 'Latitude at the time (palaeogeography)', is_numeric($o['paleolat'] ?? null) ? (float) $o['paleolat'] : null);
        $add('paleo_longitude', 'Longitude at the time (palaeogeography)', is_numeric($o['paleolng'] ?? null) ? (float) $o['paleolng'] : null);
        $add('paleo_model', 'Palaeogeographic model', self::text($o['paleomodel'] ?? null));
        $add('primary_reference', 'Primary reference', self::text($o['primary_reference'] ?? null));
        $place = is_numeric($o['lat'] ?? null) && is_numeric($o['lng'] ?? null) ? new Point((float) $o['lat'], (float) $o['lng']) : null;
        $interval = self::text($o['early_interval'] ?? null);
        $late = self::text($o['late_interval'] ?? null);

        return new Entity(
            id: Identifier::of(Scheme::PBDB_OCCURRENCE, $id),
            label: $name.' - '.implode(', ', array_filter([self::text($o['formation'] ?? null), $interval.(null !== $late && $late !== $interval ? ' to '.$late : '')])),
            domain: Domain::TAXONOMY,
            kind: 'fossil occurrence',
            properties: $properties,
            url: 'https://paleobiodb.org/data1.2/occs/single.json?id=occ:'.$id.'&show=full',
            place: $place,
            source: 'pbdb',
            related: array_filter(['taxon' => Identifiers::of(Identifier::tryOf(Scheme::PBDB, $o['accepted_no'] ?? null))], static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }
}
