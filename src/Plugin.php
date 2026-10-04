<?php

namespace justinholtweb\carrierfreight;

use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use justinholtweb\carrier\services\Carriers;
use justinholtweb\carrierfreight\carriers\TforceCarrier;
use justinholtweb\carrierfreight\carriers\OdflCarrier;
use justinholtweb\carrierfreight\carriers\EstesCarrier;
use justinholtweb\carrierfreight\carriers\XpoCarrier;
use justinholtweb\carrierfreight\carriers\RlCarrier;
use justinholtweb\carrierfreight\carriers\SaiaCarrier;
use yii\base\Event;

/**
 * Carrier for LTL Freight.
 *
 * A carrier add-on and nothing else: no tables, no settings screen, no control panel section.
 * Carrier owns packing, checkout, label storage, the claim that stops a label being bought twice,
 * tracking, the pickup-point picker and the log; this package translates one API. That is why it
 * is free.
 *
 * One package, 6 carriers: a merchant installs one thing for the family rather than
 * guessing which of 6 plugins they need.
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '5.0.0';

    public bool $hasCpSettings = false;

    public bool $hasCpSection = false;

    public function init(): void
    {
        parent::init();

        Event::on(
            Carriers::class,
            Carriers::EVENT_REGISTER_CARRIERS,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = TforceCarrier::class;
                $event->types[] = OdflCarrier::class;
                $event->types[] = EstesCarrier::class;
                $event->types[] = XpoCarrier::class;
                $event->types[] = RlCarrier::class;
                $event->types[] = SaiaCarrier::class;
            },
        );
    }
}
