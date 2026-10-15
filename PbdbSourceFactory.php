<?php

namespace Omnilab\Pbdb;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The Paleobiology Database: taxa, fossil occurrences, a search of names,
 * and the fossils of a place and an age (fossils(), outside the contract) -
 * no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     radius_km: 10                    # the circle around a Point, for fossils() when the query gives none
 *     throttle: 0                      # seconds between two calls: PBDB publishes no rate
 *     base_uri: 'https://paleobiodb.org/data1.2/'
 */
final class PbdbSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'pbdb',
            'omnilab.required_options' => [],
            'radius_km' => 10.0,
            'throttle' => 0.0,
            'base_uri' => PbdbSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new PbdbSource(
            $this->http ?? HttpClient::create(),
            (float) $c->get('radius_km'),
            (string) $c->get('base_uri', PbdbSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
