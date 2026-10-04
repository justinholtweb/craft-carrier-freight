<?php

namespace justinholtweb\carrierfreight\carriers;

use Craft;
use justinholtweb\carrier\auth\ApiKeyAuth;
use justinholtweb\carrier\base\Accessorial;
use justinholtweb\carrier\base\AuthInterface;
use justinholtweb\carrier\base\Capabilities;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\Field;
use justinholtweb\carrier\base\HealthResult;
use justinholtweb\carrier\base\RateResult;
use justinholtweb\carrier\base\ShipmentResult;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\base\Transport;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\models\canonical\Rate;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\canonical\TrackingEvent;
use justinholtweb\carrier\models\canonical\TrackingInfo;
use justinholtweb\carrierfreight\ebol\NmftaEbol;

/**
 * R+L Carriers.
 *
 * Rating `POST /RateQuote`, the NMFTA eBOL at `POST /BillOfLading/DigitalCouncilBOL`, documents
 * `GET /BillOfLading/PrintBOL` and `GET /BillOfLading/PrintShippingLabels`, tracking
 * `GET /ShipmentTracing`, on api.rlc.com. Sources: technology.rlcarriers.com API documentation
 * and the Swagger 2.0 spec at api.rlc.com/swagger/docs/v1 ("RLC.API"), via
 * docs/research/ltl-freight.md §5.
 *
 * Authentication is a static `apiKey` header (rlcarriers.com → Resources → API Overview →
 * Access API Key). The account must be "enabled to accept external rate requests".
 *
 * Vendor traps:
 * - **No sandbox is documented.** Every BOL is real.
 * - Auth failures are **HTTP 400** ("No API Key was passed in the Header", "Invalid API Key"),
 *   not 401, so a wrong key reads as a refusal — which, for a BOL, is right: nothing was created.
 * - Countries are ISO alpha-3 (`USA`, `CAN`).
 * - Accessorials are CamelCase names, not codes. There is no notify-before-delivery service in
 *   the published list, so notify is not offered.
 * - `ServiceLevels[].NetCharge` is the total. For STD, `Charge` is gross; for every other level
 *   `Charge` is the *difference* from STD — never use `Charge`.
 * - PROs can carry a letter prefix (`I875682972`); they are always strings here.
 * - Errors arrive as `Errors[]{Property, ErrorMessage, ExceptionMessage}` alongside `Code`.
 *
 * Quote numbers: R+L honours a quote when the BOL cites it, so `createShipment()` re-quotes and
 * sends the number as NMFTA `referenceNumbers.quoteId`; `options['quoteId']` skips the re-quote.
 *
 * **UNVERIFIED**:
 * - The DigitalCouncilBOL endpoint's NMFTA version and response shape: this sends v2.0.1 with
 *   ISO-3 countries (R+L's own convention), reads `referenceNumbers.pro` or `ProNumber`, and, when
 *   the answer carries no documents, fetches them with PrintBOL and PrintShippingLabels.
 * - Whether R+L's NMFTA endpoint accepts every NMFTA accessorial code sent.
 * - PrintShippingLabels `Style=1` as the Avery sheet (the thirteen styles are not described).
 * - `PickupDate` format (MM/dd/yyyy is sent); money strings may carry `$` and commas, so they are
 *   cleaned before parsing.
 * - Guaranteed service-level codes beyond `GSHW` are not published, so only standard is offered.
 * - `ShortStatus` / `StatusCode` values are not published; statuses are mapped from the words.
 * - Pickups: `POST /PickupRequest` exists but its standalone request body is not in the research,
 *   so pickups are **not implemented**.
 * - Rate limits; the public tracking page URL.
 */
class RlCarrier extends FreightCarrier
{
    protected const RATE_ACCESSORIALS = [
        Accessorial::LIFTGATE_PICKUP => 'OriginLiftgate',
        Accessorial::LIFTGATE_DELIVERY => 'DestinationLiftgate',
        Accessorial::RESIDENTIAL_PICKUP => 'ResidentialPickup',
        Accessorial::RESIDENTIAL_DELIVERY => 'ResidentialDelivery',
        Accessorial::INSIDE_DELIVERY => 'InsideDelivery',
        Accessorial::LIMITED_ACCESS_PICKUP => 'LimitedAccessPickup',
        Accessorial::LIMITED_ACCESS_DELIVERY => 'LimitedAccessDelivery',
        Accessorial::APPOINTMENT => 'DeliveryAppointment',
        Accessorial::HAZMAT => 'Hazmat',
    ];

    protected const BOL_ACCESSORIALS = [
        Accessorial::LIFTGATE_PICKUP => 'LFTP',
        Accessorial::LIFTGATE_DELIVERY => 'LFTD',
        Accessorial::RESIDENTIAL_PICKUP => 'REP',
        Accessorial::RESIDENTIAL_DELIVERY => 'RES',
        Accessorial::INSIDE_DELIVERY => 'IDL',
        Accessorial::LIMITED_ACCESS_PICKUP => 'LTDAP',
        Accessorial::LIMITED_ACCESS_DELIVERY => 'LTDAD',
        Accessorial::APPOINTMENT => 'APTD',
        Accessorial::HAZMAT => 'HAZ',
    ];

    protected const REQUOTE_FOR_BOL = true;

    public static function handle(): string
    {
        return 'freight-rl';
    }

    public static function displayName(): string
    {
        return 'R+L Carriers';
    }

    public static function description(): string
    {
        return Craft::t('carrier', 'LTL quotes, NMFTA electronic bills of lading with labels, and PRO tracking from R+L Carriers.');
    }

    public static function setupUrl(): ?string
    {
        return 'https://technology.rlcarriers.com/api-documentation/getting-started/';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Feature::FREIGHT, Feature::RATES, Feature::LABELS, Feature::TRACKING)
            ->labelFormats(LabelFormat::PDF_LETTER)
            ->originCountries('US', 'CA')
            ->accessorials(...static::supportedAccessorials());
    }

    public static function settingsFields(): array
    {
        return array_merge([
            Field::secret('apiKey', 'API key', [
                'required' => true,
                'instructions' => 'rlcarriers.com → Resources → API Overview → Access API Key. R+L has no test environment: every bill of lading is real.',
            ]),
        ], static::freightFields());
    }

    public static function services(): array
    {
        return [
            'standard' => ['name' => 'R+L Standard LTL', 'international' => false, 'points' => false],
        ];
    }

    /** UNVERIFIED: R+L's public tracing page. */
    public static function trackingUrl(string $trackingNumber): ?string
    {
        return 'https://www.rlcarriers.com/freight/shipping/shipment-tracing?pro=' . rawurlencode($trackingNumber);
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new ApiKeyAuth('apiKey', 'apiKey', '');
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri('https://api.rlc.com')
            ->setDefaultHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json']);
    }

    protected function probe(): HealthResult
    {
        $response = $this->transport()->get('RateQuote/GetPalletTypes');

        if (!$response->ok()) {
            return HealthResult::fail($this->redactSecrets($response->errorMessage()), [
                Craft::t('carrier', 'R+L answers a missing or wrong key with HTTP 400. Copy the key again from rlcarriers.com, and make sure the account is enabled for external rate requests.'),
            ]);
        }

        return HealthResult::pass(Craft::t('carrier', 'Connected to R+L Carriers.'), [
            'Environment' => 'Production (R+L has no test environment)',
        ]);
    }

    // =========================================================================
    // Rates
    // =========================================================================

    public function rate(ShipmentRequest $request): RateResult
    {
        $units = $this->handlingUnits($request, $error);

        if ($error !== null) {
            return RateResult::failed($error);
        }

        if ($request->serviceCode !== '' && $request->serviceCode !== 'standard') {
            return RateResult::failed(Craft::t('carrier', 'Unknown R+L service “{code}”.', ['code' => $request->serviceCode]));
        }

        $items = [];

        foreach ($units as $unit) {
            $item = ['Class' => $unit['class'], 'Weight' => $unit['weightLb']];

            if ($unit['hasDims']) {
                $item += ['Length' => $unit['lengthIn'], 'Width' => $unit['widthIn'], 'Height' => $unit['heightIn']];
            }

            $items[] = $item;
        }

        $services = self::codesFor($request, static::RATE_ACCESSORIALS);

        if (self::anyHazmat($units) && !in_array('Hazmat', $services, true)) {
            $services[] = 'Hazmat';
        }

        $quote = [
            'PickupDate' => self::shipDate($request)->format('m/d/Y'),
            'Origin' => $this->location($request->shipFrom),
            'Destination' => $this->location($request->shipTo),
            'Items' => $items,
            'DeclaredValue' => 0,
        ];

        if ($services !== []) {
            $quote['AdditionalServices'] = $services;
        }

        $response = $this->transport()->post('RateQuote', ['RateQuote' => $quote]);

        if (!$response->ok() || array_filter((array)$response->at('Errors', [])) !== []) {
            return RateResult::failed($this->rateError($response));
        }

        $rates = [];

        foreach ((array)$response->at('RateQuote.ServiceLevels', []) as $level) {
            if (!is_array($level) || strtoupper((string)($level['Code'] ?? '')) !== 'STD') {
                continue;
            }

            $amount = self::money($level['NetCharge'] ?? null);

            if ($amount === null || $amount <= 0) {
                continue;
            }

            $days = $level['ServiceDays'] ?? null;

            $rates[] = new Rate([
                'serviceCode' => 'standard',
                'serviceName' => self::services()['standard']['name'],
                'amount' => $amount,
                'currency' => 'USD',
                'transitDays' => is_numeric($days) ? (int)$days : null,
                'quoteId' => (string)($level['QuoteNumber'] ?? ''),
            ]);
        }

        $messages = [];

        foreach ((array)$response->at('RateQuote.CategorizedMessages', []) as $group) {
            foreach ((array)$group as $message) {
                if (is_string($message) && $message !== '') {
                    $messages[] = $message;
                }
            }
        }

        return RateResult::ok($rates, $messages);
    }

    private function location(?Address $address): array
    {
        return [
            'City' => $address?->city ?? '',
            'StateOrProvince' => $address?->region ?? '',
            'ZipOrPostalCode' => $address ? self::postcode($address) : '',
            'CountryCode' => self::iso3($address?->countryCode ?? 'US'),
        ];
    }

    // =========================================================================
    // Bill of lading
    // =========================================================================

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        if ($request->serviceCode !== 'standard') {
            return ShipmentResult::rejected(Craft::t('carrier', 'Unknown R+L service “{code}”.', ['code' => $request->serviceCode]));
        }

        $count = max(1, count($request->parcels));

        return $this->createEbolShipment(
            $request,
            new NmftaEbol(version: '2.0.1', iso3: true, palletCode: 'PLT', quoteField: 'quoteId', labelFormats: [LabelFormat::PDF_LETTER => 'Avery']),
            'BillOfLading/DigitalCouncilBOL',
            [],
            function(string $pro, string $format) use ($count) {
                $bol = $this->transport()->get('BillOfLading/PrintBOL', ['ProNumber' => $pro]);
                $labels = $this->transport()->get('BillOfLading/PrintShippingLabels', [
                    'ProNumber' => $pro,
                    'Style' => 1,
                    'StartPosition' => 1,
                    'NumberOfLabels' => $count,
                ]);

                return [
                    'bol' => $bol->ok() ? self::document($bol->at('BolDocument'), LabelFormat::PDF_LETTER, 'bol', $pro) : null,
                    'labels' => $labels->ok() ? [self::document($labels->at('ShippingLabelsFile'), LabelFormat::PDF_LETTER, 'label', $pro)] : [],
                ];
            },
        );
    }

    // =========================================================================
    // Tracking
    // =========================================================================

    public function track(array $trackingNumbers): array
    {
        $out = [];

        foreach ($trackingNumbers as $number) {
            $response = $this->transport()->get('ShipmentTracing', ['TraceNumbers' => (string)$number, 'TraceType' => 'PRO']);

            if (!$response->ok()) {
                continue;
            }

            $shipment = $response->at('Shipments.0');

            if (!is_array($shipment)) {
                $out[] = new TrackingInfo(['trackingNumber' => (string)$number, 'notFound' => true]);
                continue;
            }

            $events = [];

            foreach ((array)($shipment['StatusHistory'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $description = (string)($entry['Description'] ?? '');

                $events[] = new TrackingEvent([
                    'occurredAt' => self::parseDate(trim((string)($entry['Date'] ?? '') . ' ' . (string)($entry['Time'] ?? '')), 'America/New_York'),
                    'status' => self::statusFromText($description),
                    'code' => (string)($entry['StatusCode'] ?? ''),
                    'description' => $description,
                    'location' => self::place($entry['City'] ?? '', $entry['State'] ?? ''),
                ]);
            }

            $text = trim((string)($shipment['ShortStatus'] ?? '') . ' ' . (string)($shipment['LongStatus'] ?? ''));
            $deliveredOn = (string)($shipment['DeliveryDate']['Date'] ?? '');

            $status = match (true) {
                array_filter((array)($shipment['ServiceDelayInfos'] ?? [])) !== [] && $deliveredOn === '' => TrackingStatus::EXCEPTION,
                $deliveredOn !== '' && self::statusFromText($text) !== TrackingStatus::EXCEPTION => TrackingStatus::DELIVERED,
                $text === '' && empty($shipment['PickupDate']['Date']) => TrackingStatus::PRE_TRANSIT,
                default => self::statusFromText($text, empty($shipment['PickupDate']['Date']) ? TrackingStatus::PRE_TRANSIT : TrackingStatus::IN_TRANSIT),
            };

            $out[] = new TrackingInfo([
                'trackingNumber' => (string)$number,
                'status' => $status,
                'statusText' => (string)($shipment['LongStatus'] ?? $shipment['ShortStatus'] ?? TrackingStatus::label($status)),
                'estimatedDelivery' => self::parseDate((string)($shipment['EstimatedDelivery'] ?? ''), 'America/New_York'),
                'deliveredAt' => $status === TrackingStatus::DELIVERED
                    ? self::parseDate(trim($deliveredOn . ' ' . (string)($shipment['DeliveryDate']['Time'] ?? '')), 'America/New_York')
                    : null,
                'events' => $events,
            ]);
        }

        return $out;
    }
}
