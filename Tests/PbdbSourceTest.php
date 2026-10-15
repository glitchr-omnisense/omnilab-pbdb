<?php

namespace Omnilab\Pbdb\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\BBox;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Pbdb\PbdbSource;
use Omnilab\Pbdb\PbdbSourceFactory;
use Omnilab\Source\Capability;
use Omnilab\Source\OccurrenceQuery;
use Omnilab\Source\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from paleobiodb.org/data1.2 on 2026-10-16
 * (Tests/Fixtures/index.json names each one's address): Tyrannosaurus rex
 * by number and name, Felis leo and Panthera leo, unknown numbers and
 * names (404), a fossil by its number (occ:139292), the dinosaurs of 70 to
 * 66 Ma in a box over Montana and Wyoming (two pages of 50), T. rex within
 * 100 km of a point, Tyrannosaurus in the US in the Maastrichtian, and the
 * autocompletion of "Tyranno".
 */
final class PbdbSourceTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

    private function source(): PbdbSource
    {
        $fixtures = __DIR__.'/Fixtures';
        $index = json_decode((string) file_get_contents($fixtures.'/index.json'), true);
        $http = new MockHttpClient(function (string $method, string $url, array $o) use ($fixtures, $index): MockResponse {
            $this->urls[] = rawurldecode($url);
            $name = self::fixture($method, $url, $o['body'] ?? null);
            if (!isset($index[$name])) {
                self::fail('No recorded answer for '.rawurldecode($url));
            }

            return new MockResponse((string) file_get_contents($fixtures.'/'.$name), ['http_code' => $index[$name]['status']]);
        });
        $source = (new PbdbSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
        self::assertInstanceOf(PbdbSource::class, $source);

        return $source;
    }

    private static function fixture(string $method, string $url, ?string $body): string
    {
        $parts = parse_url($url);
        $readable = strtolower(trim((string) preg_replace('~[^A-Za-z0-9]+~', '-', rawurldecode(($parts['path'] ?? '').'-'.($parts['query'] ?? ''))), '-'));

        return substr($readable, 0, 70).'-'.substr(md5($method.' '.$url.' '.$body), 0, 8).'.json';
    }

    private function dinosaurs(): OccurrenceQuery
    {
        return new OccurrenceQuery(new BBox(-110, 40, -100, 50), filters: [OccurrenceQuery::TAXON => 'Dinosauria', PbdbSource::MAX_MA => 70, PbdbSource::MIN_MA => 66], limit: 50);
    }

    public function testCapabilitiesLeaveOccurrencesOut(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SEARCH], $this->source()->capabilities());
        $this->expectException(NotSupportedException::class);
        $this->source()->occurrences($this->dinosaurs());
    }

    public function testTyrannosaurusRex(): void
    {
        $rex = $this->source()->entity('54833');

        self::assertCount(1, $this->urls);
        self::assertSame(['pbdb:54833', 'Tyrannosaurus rex', Domain::TAXONOMY, 'species'], [(string) $rex->id, $rex->label, $rex->domain, $rex->kind]);
        self::assertSame(['Osborn 1905', 'tyrant lizard king', false, 70], [$rex->value('taxon_authority'), $rex->value('common_name'), $rex->value('extant'), $rex->value('occurrence_count')]);
        self::assertSame(['Campanian', 'Maastrichtian'], [$rex->value('early_interval'), $rex->value('late_interval')]);
        self::assertEquals([new Quantity(83.6, 'Ma'), new Quantity(72.2, 'Ma'), new Quantity(72.2, 'Ma'), new Quantity(66, 'Ma')], [$rex->value('first_appearance_max_ma'), $rex->value('first_appearance_min_ma'), $rex->value('last_appearance_max_ma'), $rex->value('last_appearance_min_ma')]);
        self::assertSame(['Chordata', 'Reptilia', null, 'Tyrannosauridae', 'Tyrannosaurus'], [$rex->value('phylum'), $rex->value('class'), $rex->value('order'), $rex->value('family'), $rex->value('genus')], 'PBDB\'s NO_ORDER_SPECIFIED left out');
        self::assertSame(['terrestrial', 'carnivore', 'ground dwelling, solitary'], [$rex->value('environment'), $rex->value('diet'), $rex->value('life_habit')]);
        self::assertStringStartsWith('H. F. Osborn. 1905. Tyrannosaurus and other Cretaceous carnivorous dinosaurs', $rex->value('primary_reference'));
        self::assertSame('https://paleobiodb.org/data1.2/taxa/thumb.png?id=12189', $rex->image);
        self::assertSame(['pbdb:38613'], $rex->related('parent')->keys());
        self::assertSame(['pbdb:54833'], $rex->identifiers()->keys(), 'PBDB gives no other base\'s id');
    }

    public function testByNameAVariantGivesItsTaxon(): void
    {
        self::assertSame('pbdb:54833', (string) $this->source()->entity('Tyrannosaurus rex')?->id);
        self::assertSame('pbdb:54833', (string) $this->source()->entity('txn:54833')?->id);
        $lion = $this->source()->entity('Felis leo');
        self::assertSame(['pbdb:46521', 'Panthera leo', true], [(string) $lion->id, $lion->label, $lion->value('extant')]);
        self::assertContains('Felis leo', $lion->synonyms);
    }

    public function testUnknownIsNullOtherSchemesRefused(): void
    {
        self::assertNull($this->source()->entity('999999999'));
        self::assertNull($this->source()->entity('Nothingus nowhereus'));
        self::assertNull($this->source()->entity('occ:999999999'));
        $this->urls = [];
        foreach (['col:4CGXP', Identifier::of(Scheme::GBIF, '4CGXP'), 'Q140'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail('not read: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->urls);
    }

    public function testAFossilHasAPlaceAndNoTime(): void
    {
        $fossil = $this->source()->entity(Identifier::of(Scheme::PBDB_OCCURRENCE, '139292'));

        self::assertSame(['pbdb.occurrence:139292', 'fossil occurrence', 'Tyrannosaurus rex - Scollard, Late Maastrichtian'], [(string) $fossil->id, $fossil->kind, $fossil->label]);
        self::assertNull($fossil->time, 'an age is no date');
        self::assertEquals(new Point(51.906399, -113.0289), $fossil->place);
        self::assertEquals([new Quantity(72.2, 'Ma'), new Quantity(66, 'Ma')], [$fossil->value('max_ma'), $fossil->value('min_ma')]);
        self::assertSame(['CA', 'Alberta', 64.06, -68.22], [$fossil->value('country'), $fossil->value('state'), $fossil->value('paleo_latitude'), $fossil->value('paleo_longitude')]);
        self::assertSame(['pbdb:54833'], $fossil->related('taxon')->keys());
    }

    public function testTheFossilsOfABoxAndAnAgeInTwoPages(): void
    {
        $source = $this->source();
        $first = $source->fossils($this->dinosaurs());
        $second = $source->fossils($this->dinosaurs()->with(['cursor' => $first->next]));

        self::assertStringContainsString('occs/list.json?base_name=Dinosauria&lngmin=-110&lngmax=-100&latmin=40&latmax=50&max_ma=70&min_ma=66', $this->urls[0]);
        self::assertSame([1282, 50, '50', 50, '100'], [$first->total, \count($first), $first->next, \count($second), $second->next]);
        foreach ([...$first->items, ...$second->items] as $fossil) {
            self::assertTrue((new BBox(-110, 40, -100, 50))->contains($fossil->place));
            self::assertLessThan(70, $fossil->property('min_ma')->number());
            self::assertGreaterThan(66, $fossil->property('max_ma')->number());
        }
    }

    public function testACircleACountryAndAnInterval(): void
    {
        $source = $this->source();
        $circle = $source->fossils(new OccurrenceQuery(new Point(47.6, -106.9), filters: [OccurrenceQuery::TAXON => Identifier::of(Scheme::PBDB, '54833')], radius: 100));
        self::assertMatchesRegularExpression('~base_id=txn:54833&loc=POLYGON\(\((?:[-\d.]+ [-\d.]+,){64}[-\d.]+ [-\d.]+\)\)~', $this->urls[0]);
        self::assertSame(10, $circle->total);

        $us = $source->fossils(new OccurrenceQuery(filters: [OccurrenceQuery::TAXON => 'Tyrannosaurus', OccurrenceQuery::COUNTRY => 'us', PbdbSource::INTERVAL => 'Maastrichtian'], limit: 5));
        self::assertStringContainsString('base_name=Tyrannosaurus&cc=US&interval=Maastrichtian', $this->urls[1]);
        self::assertSame(74, $us->total);
    }

    public function testADateASortOrAnUnknownFilterIsRefusedBeforeAnyCall(): void
    {
        $source = $this->source();
        foreach ([
            $this->dinosaurs()->with(['from' => new \DateTimeImmutable('2020-01-01')]),
            $this->dinosaurs()->with(['sort' => OccurrenceQuery::OLDEST]),
            $this->dinosaurs()->with(['filters' => [OccurrenceQuery::MIN_MAGNITUDE => 4]]),
        ] as $query) {
            try {
                $source->fossils($query);
                self::fail('not refused');
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->urls);
    }

    public function testSearchIsPbdbsAutocompletion(): void
    {
        $page = $this->source()->search(new Query('Tyranno', limit: 20));

        self::assertSame(['https://paleobiodb.org/data1.2/taxa/auto.json?name=Tyranno&limit=20&vocab=pbdb'], $this->urls);
        self::assertSame('Tyrannosauropus', $page->first()->label, 'the most recorded first');
        self::assertContains('Tyrannosaurus', array_map(static fn ($e) => $e->label, $page->items));
        self::assertNotContains('tyrannosaurid', array_map(static fn ($e) => $e->label, $page->items), 'one entity a taxon');
        self::assertNull($page->next);
        self::assertSame([], $this->source()->search(new Query('Tyranno', cursor: '20'))->items, 'no second page');
    }
}
