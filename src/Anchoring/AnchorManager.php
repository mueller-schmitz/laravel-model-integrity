<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Manager;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\Anchor;
use MuellerSchmitz\ModelIntegrity\Anchoring\Drivers\DiskAnchor;
use MuellerSchmitz\ModelIntegrity\Anchoring\Drivers\OpenTimestampsAnchor;
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

        if ($calendars === []) {
            throw IntegrityConfigurationException::invalidConfig('model-integrity.anchors.opentimestamps.calendars', 'a list of https:// calendar URLs');
        }

        return new OpenTimestampsAnchor(
            $this->container->make(CalendarClient::class),
            $this->container->make(Codec::class),
            $this->container->make(EsploraClient::class),
            $calendars,
            Config::integer('model-integrity.anchors.opentimestamps.min_calendars', 2),
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
