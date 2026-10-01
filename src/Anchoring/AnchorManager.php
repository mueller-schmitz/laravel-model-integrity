<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Manager;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\Anchor;
use MuellerSchmitz\ModelIntegrity\Anchoring\Drivers\DiskAnchor;
use MuellerSchmitz\ModelIntegrity\Anchoring\Drivers\OpenTimestampsAnchor;
use MuellerSchmitz\ModelIntegrity\Anchoring\Drivers\Rfc3161Anchor;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\CalendarClient;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Codec;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\EsploraClient;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;

/**
 * Resolves anchor drivers by name. Custom drivers are registered with
 * extend(), e.g. in a service provider:
 *
 *     app(AnchorManager::class)->extend('archive', fn () => new ArchiveAnchor);
 *
 * @method Anchor driver(string|null $driver = null)
 */
class AnchorManager extends Manager
{
    /**
     * The drivers model-integrity:anchor submits to.
     *
     * @return list<string>
     */
    public function enabledDrivers(): array
    {
        $drivers = Config::get('model-integrity.anchors.drivers', []);
        $names = [];

        foreach (is_array($drivers) ? $drivers : [null] as $driver) {
            if (! is_string($driver) || $driver === '') {
                throw IntegrityConfigurationException::invalidConfig('model-integrity.anchors.drivers', 'a list of driver names');
            }

            $names[] = $driver;
        }

        return array_values(array_unique($names));
    }

    public function getDefaultDriver(): string
    {
        return $this->enabledDrivers()[0] ?? throw IntegrityConfigurationException::invalidConfig('model-integrity.anchors.drivers', 'at least one driver name');
    }

    protected function createOpentimestampsDriver(): Anchor
    {
        $calendars = [];

        foreach (Config::array('model-integrity.anchors.opentimestamps.calendars', []) as $calendar) {
            if (! is_string($calendar) || ! str_starts_with($calendar, 'https://')) {
                throw IntegrityConfigurationException::invalidConfig('model-integrity.anchors.opentimestamps.calendars', 'a list of https:// calendar URLs');
            }

            $calendars[] = $calendar;
        }

        // No calendars is valid: existing proofs can still be verified.
        return new OpenTimestampsAnchor(
            $this->container->make(CalendarClient::class),
            $this->container->make(Codec::class),
            $this->container->make(EsploraClient::class),
            $calendars,
            Config::integer('model-integrity.anchors.opentimestamps.min_calendars', 2),
        );
    }

    protected function createRfc3161Driver(): Anchor
    {
        $url = config('model-integrity.anchors.rfc3161.url');
        $caFile = config('model-integrity.anchors.rfc3161.ca_file');
        $intermediates = config('model-integrity.anchors.rfc3161.intermediates_file');
        $policy = config('model-integrity.anchors.rfc3161.policy');
        $headers = [];

        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw IntegrityConfigurationException::invalidConfig('model-integrity.anchors.rfc3161.url', 'the https:// URL of a time-stamp authority');
        }

        if (! is_string($caFile) || $caFile === '') {
            throw IntegrityConfigurationException::invalidConfig('model-integrity.anchors.rfc3161.ca_file', 'the path of the PEM file with the CA certificates of the time-stamp authority');
        }

        foreach (Config::array('model-integrity.anchors.rfc3161.headers', []) as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                throw IntegrityConfigurationException::invalidConfig('model-integrity.anchors.rfc3161.headers', 'an array of header names and values');
            }

            $headers[$name] = $value;
        }

        return new Rfc3161Anchor(
            $this->container->make(Factory::class),
            $url,
            $caFile,
            is_string($intermediates) && $intermediates !== '' ? $intermediates : null,
            is_string($policy) && $policy !== '' ? $policy : null,
            Config::integer('model-integrity.anchors.rfc3161.timeout', 10),
            $headers,
        );
    }

    protected function createDiskDriver(): Anchor
    {
        return new DiskAnchor(
            Config::string('model-integrity.anchors.disk.disk', 'local'),
            Config::string('model-integrity.anchors.disk.path', 'integrity-anchors'),
        );
    }
}
