<?php

namespace justinholtweb\carrierfreight\carriers;

use Craft;
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
use justinholtweb\carrier\models\canonical\Rate;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\canonical\TrackingEvent;
use justinholtweb\carrier\models\canonical\TrackingInfo;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrierfreight\auth\SessionTokenAuth;
use justinholtweb\carrierfreight\ebol\NmftaEbol;

/**
 * Estes Express Lines.
 *
 * Rating `POST /v1/rate-quotes`, the NMFTA eBOL (v2.0.1) at `POST /v1/bol`, tracking
 * `GET /v1/shipments/history?pro=`. Production cloudapi.estes-express.com, test
 * uat-cloudapi.estes-express.com. Sources: developer.estes-express.com and the OpenAPI spec at
 * cloudapi.estes-express.com/specs/swagger.yaml ("Estes Express API Suite"), via
 * docs/research/ltl-freight.md §3, plus a direct reading of the swagger for the eBOL's
 * `version` values ("v2.0"/"v2.0.1"), `referenceNumbers.quoteID` (7 chars, capital ID), the label
 * formats (Avery, Avery2, Avery4, Letter, Zebra) and `lineItemLayout: "Nested"`.
 *
 * Authentication has three layers, and every API call needs two of them:
 * 1. Estes emails a client ID and secret (separate for test and production).
 * 2. `POST /v1/api-key` with Basic clientId:clientSecret mints the **API key** once per
 *    environment — and replaces the emailed secret. The merchant does this once; the key is
 *    what this connection stores.
 * 3. `POST /authenticate` with header `apikey: <key>` and Basic **MyEstes username:password**
 *    returns a bearer token for ~10 hours (`expires_in` 35999).
 * Every call then sends **both** `Authorization: Bearer …` **and** `apikey: …`. The schema says the
 * token key is `token`, the documented sample says `access_token`; both are read.
 *
 * Vendor traps:
 * - In rating, **`PT` is pallet and `PL` is pail**. The eBOL uses NMFTA codes (`PLT`, `SKD`) instead.
 * - Rating accessorials (`LGATE`, `HD`, `APT`) differ from eBOL ones (`LFTD`, `RES`, `APTD`).
 *   The eBOL does **not** accept `APTP`.
 * - `quoteRate.totalCharges` is a string; a rated accessorial's `charge` can be "Included".
 * - Every response carries `error{code,message,details}`, with code 0 on success — checked even
 *   on a 200. `rateFound: false` plus `reasons[]` explains a missing rate.
 * - A limited-access *delivery* has no generic rating code (only site types such as `CONST`,
 *   `PRISDL`), so it is not offered: the eBOL's `LTDAD` would print a service nobody priced.
 * - Guaranteed (`LTLTC`), volume (`VTL`) and exclusive-use levels require a tare weight Carrier's
 *   parcels do not carry, so only standard LTL is offered.
 * - Alaska has its own accessorial codes (`AKLGAT`, `AKRES` …); this does not map them.
 *
 * Quote numbers: the eBOL cites `referenceNumbers.quoteID`, so `createShipment()` re-quotes the
 * shipped service and cites it; `options['quoteId']` skips the re-quote.
 *
 * **UNVERIFIED**:
 * - The eBOL party shape (see `NmftaEbol`), and whether `MNC` (notify) is accepted — Estes' list
 *   does not show it, so notify is not offered.
 * - Tracking: `/v1/shipments/history` is in the research but **not** among the paths in the
 *   published swagger.yaml as read on 2026-10-03; its response fields come from the developer site.
 * - Pickups: the research names `POST /v1/pickup-requests` (→ `data.requestNumber`) but neither it
 *   nor the published swagger shows the request body, so pickups are **not implemented**.
 * - Rate limits (429 appears only on subscription endpoints).
 * - The public tracking page URL.
 */
class EstesCarrier extends FreightCarrier
{
    protected const RATE_ACCESSORIALS = [
        Accessorial::LIFTGATE_PICKUP => 'LGATEP',
        Accessorial::LIFTGATE_DELIVERY => 'LGATE',
        Accessorial::RESIDENTIAL_PICKUP => 'HPU',
        Accessorial::RESIDENTIAL_DELIVERY => 'HD',
        Accessorial::INSIDE_DELIVERY => 'INS',
        Accessorial::LIMITED_ACCESS_PICKUP => 'LADPU',
        Accessorial::APPOINTMENT => 'APT',
        Accessorial::NOTIFY => 'NCM',
        Accessorial::HAZMAT => 'HAZ',
    ];

    /** NMFTA codes, less MNC (not on Estes' list). */
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

    /** Rating handling-unit codes. PT is pallet; PL is pail. */
    private const RATE_PACKAGING = [
        'pallet' => 'PT',
        'skid' => 'SK',
        'crate' => 'CR',
        'carton' => 'CT',
        'box' => 'BX',
        'drum' => 'DR',
        'tote' => 'TO',
        'bag' => 'BG',
        'pail' => 'PL',
        'roll' => 'RL',
        'reel' => 'RE',
        'bundle' => 'BD',
    ];

    public static function handle(): string
    {
        return 'freight-estes';
    }

    public static function displayName(): string
    {
        return 'Estes Express';
    }

    public static function description(): string
    {
        return Craft::t('carrier', 'LTL quotes, NMFTA electronic bills of lading with labels, and PRO tracking from Estes Express Lines.');
    }

    public static function setupUrl(): ?string
    {
        return 'https://developer.estes-express.com/';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Feature::FREIGHT, Feature::RATES, Feature::LABELS, Feature::TRACKING)
            ->labelFormats(LabelFormat::PDF_LETTER, LabelFormat::PDF_4X6)
            ->originCountries('US', 'CA')
            ->accessorials(...static::supportedAccessorials())
            ->withSandbox();
    }

    public static function settingsFields(): array
    {
        return array_merge([
            Field::environment([
                'instructions' => 'Sandbox uses uat-cloudapi.estes-express.com. Estes issues separate credentials and API keys for each environment.',
            ]),
            Field::secret('apiKey', 'API key', [
                'required' => true,
                'instructions' => 'Created once per environment with POST /v1/api-key using the client ID and secret Estes emailed you (WebSupport@estes-express.com). That call replaces the emailed secret.',
            ]),
            Field::text('username', 'MyEstes username', ['required' => true]),
            Field::secret('password', 'MyEstes password', ['required' => true]),
            Field::text('accountNumber', 'Estes account number', [
                'instructions' => 'Your shipper account, for contract pricing.',
            ]),
        ], static::freightFields());
    }

    public static function services(): array
    {
        return [
            'standard' => ['name' => 'Estes LTL Standard', 'international' => false, 'points' => false],
        ];
    }

    /** UNVERIFIED: Estes' public tracking page. */
    public static function trackingUrl(string $trackingNumber): ?string
    {
        return 'https://www.estes-express.com/myestes/shipment-tracking/?type=PRO&query=' . rawurlencode($trackingNumber);
    }

    private static function hostFor(Connection $connection): string
    {
        return $connection->getSetting('environment', 'production') === 'sandbox'
            ? 'https://uat-cloudapi.estes-express.com'
            : 'https://cloudapi.estes-express.com';
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new SessionTokenAuth(
            static fn(Connection $c) => ['POST', self::hostFor($c) . '/authenticate', ['headers' => [
                'apikey' => SessionTokenAuth::settingOf($c, 'apiKey'),
                'Authorization' => 'Basic ' . base64_encode(SessionTokenAuth::settingOf($c, 'username') . ':' . SessionTokenAuth::settingOf($c, 'password')),
                'Accept' => 'application/json',
            ]]],
            ['apiKey', 'username', 'password'],
            ['access_token', 'token', 'data.access_token', 'data.token'],
            36000,
            ['apiKey' => 'apikey'],
        );
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri(self::hostFor($this->connection))
            ->setDefaultHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json']);
    }

    protected function probe(): HealthResult
    {
        $auth = $this->auth();

        if (!$auth instanceof SessionTokenAuth || $auth->accessToken() === null) {
            return HealthResult::fail(
                Craft::t('carrier', 'Estes did not issue a token for this API key and MyEstes login.'),
                [Craft::t('carrier', 'The API key must belong to the same environment as this connection, and the MyEstes login must be active. Keys are created with POST /v1/api-key.')],
            );
        }

        return HealthResult::pass(Craft::t('carrier', 'Signed in to Estes as {user}.', ['user' => (string)$this->setting('username')]), [
            'Host' => $this->isSandbox() ? 'uat-cloudapi.estes-express.com' : 'cloudapi.estes-express.com',
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
            return RateResult::failed(Craft::t('carrier', 'Unknown Estes service “{code}”.', ['code' => $request->serviceCode]));
        }

        $handlingUnits = [];

        foreach ($units as $unit) {
            $type = self::packagingCode($unit['packaging'], self::RATE_PACKAGING);
            $handlingUnit = [
                'count' => 1,
                'type' => $type,
                'weight' => $unit['weightLb'],
                'weightUnit' => 'Pounds',
            ];

            if ($unit['hasDims']) {
                $handlingUnit += ['length' => $unit['lengthIn'], 'width' => $unit['widthIn'], 'height' => $unit['heightIn'], 'dimensionsUnit' => 'Inches'];
            }

            $handlingUnit += [
                'isStackable' => false,
                'isTurnable' => true,
                'lineItems' => [NmftaEbol::clean([
                    'description' => mb_substr($unit['description'], 0, 100),
                    'weight' => $unit['weightLb'],
                    'pieces' => $unit['pieces'],
                    'packagingType' => $type,
                    'classification' => $unit['class'],
                    'nmfc' => $unit['nmfcItem'],
                    'nmfcSub' => $unit['nmfcSub'],
                    'isHazardous' => $unit['hazmat'],
                ])],
            ];

            $handlingUnits[] = $handlingUnit;
        }

        $codes = self::codesFor($request, static::RATE_ACCESSORIALS);

        if (self::anyHazmat($units) && !in_array('HAZ', $codes, true)) {
            $codes[] = 'HAZ';
        }

        $from = $request->shipFrom;
        $body = [
            'quoteRequest' => [
                'shipDate' => self::shipDate($request)->format('Y-m-d'),
                'shipTime' => (string)$request->option('shipTime', '12:00'),
                'serviceLevels' => ['LTL'],
            ],
            'payment' => NmftaEbol::clean([
                'account' => (string)$this->setting('accountNumber', ''),
                'payor' => 'Shipper',
                'terms' => 'Prepaid',
            ]),
            'requestor' => NmftaEbol::clean([
                'name' => $from->contactName(),
                'phone' => self::usPhone($from->phone),
                'email' => $from->email,
            ]),
            'origin' => ['address' => $this->location($from)],
            'destination' => ['address' => $this->location($request->shipTo)],
            'commodity' => ['handlingUnits' => $handlingUnits],
        ];

        if ($codes !== []) {
            $body['accessorials'] = ['codes' => $codes];
        }

        $response = $this->transport()->post('v1/rate-quotes', $body);
        $envelopeError = (int)$response->at('error.code', 0);

        if (!$response->ok() || $envelopeError !== 0) {
            return RateResult::failed($this->rateError($response, trim((string)$response->at('error.message', '') . ' ' . (string)$response->at('error.details', ''))));
        }

        $rates = [];
        $warnings = [];

        foreach ((array)$response->at('data', []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $amount = self::money($entry['quoteRate']['totalCharges'] ?? null);

            if (($entry['rateFound'] ?? true) === false || $amount === null || $amount <= 0) {
                foreach ((array)($entry['reasons'] ?? []) as $reason) {
                    if (is_array($reason) && !empty($reason['message'])) {
                        $warnings[] = (string)$reason['message'];
                    }
                }

                continue;
            }

            if (strtoupper((string)($entry['serviceLevelId'] ?? 'LTL')) !== 'LTL') {
                continue;
            }

            $days = $entry['transitDetails']['transitDays'] ?? null;

            $rates[] = new Rate([
                'serviceCode' => 'standard',
                'serviceName' => self::services()['standard']['name'],
                'amount' => $amount,
                'currency' => 'USD',
                'transitDays' => is_numeric($days) ? (int)$days : null,
                'estimatedDelivery' => isset($entry['dates']['transitDeliveryDate']) ? (string)$entry['dates']['transitDeliveryDate'] : null,
                'quoteId' => (string)($entry['quoteId'] ?? ''),
                'notes' => array_values(array_filter(array_map(
                    static fn($alert) => is_array($alert) ? (string)($alert['message'] ?? '') : '',
                    (array)($entry['alerts'] ?? []),
                ))),
            ]);
        }

        return RateResult::ok($rates, $warnings);
    }

    private function location(?\justinholtweb\carrier\models\canonical\Address $address): array
    {
        if ($address === null) {
            return [];
        }

        return NmftaEbol::clean([
            'city' => $address->city,
            'stateProvince' => $address->region,
            'postalCode' => self::postcode($address),
            'country' => strtoupper($address->countryCode),
        ]);
    }

    // =========================================================================
    // Bill of lading
    // =========================================================================

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        if ($request->serviceCode !== 'standard') {
            return ShipmentResult::rejected(Craft::t('carrier', 'Unknown Estes service “{code}”.', ['code' => $request->serviceCode]));
        }

        return $this->createEbolShipment(
            $request,
            new NmftaEbol(
                version: 'v2.0.1',
                iso3: false,
                palletCode: 'PLT',
                quoteField: 'quoteID',
                labelFormats: [LabelFormat::PDF_LETTER => 'Avery', LabelFormat::PDF_4X6 => 'Zebra'],
            ),
            'v1/bol',
        );
    }

    // =========================================================================
    // Tracking
    // =========================================================================

    public function track(array $trackingNumbers): array
    {
        $out = [];

        foreach ($trackingNumbers as $number) {
            $response = $this->transport()->get('v1/shipments/history', ['pro' => (string)$number]);

            if (!$response->ok()) {
                if ($response->status === 404) {
                    $out[] = new TrackingInfo(['trackingNumber' => (string)$number, 'notFound' => true]);
                }

                continue;
            }

            $shipment = $response->at('data.0');

            if (!is_array($shipment)) {
                $out[] = new TrackingInfo(['trackingNumber' => (string)$number, 'notFound' => true]);
                continue;
            }

            $events = [];

            foreach ((array)($shipment['statusHistory'] ?? []) as $entry) {
                if (is_array($entry)) {
                    $events[] = $this->event($entry);
                }
            }

            $current = is_array($shipment['status'] ?? null) ? $this->event($shipment['status']) : ($events[0] ?? null);
            $status = $current?->status ?? TrackingStatus::PRE_TRANSIT;

            if ($events === [] && $current !== null) {
                $events[] = $current;
            }

            $delivered = self::parseDate(trim((string)($shipment['deliveryDate'] ?? '') . ' ' . (string)($shipment['deliveryTime'] ?? '')), 'America/New_York');

            $out[] = new TrackingInfo([
                'trackingNumber' => (string)$number,
                'status' => $status,
                'statusText' => $current?->description ?? TrackingStatus::label($status),
                'estimatedDelivery' => self::parseDate((string)($shipment['estimatedDelivery']['endDate'] ?? $shipment['estimatedDelivery']['startDate'] ?? ''), 'America/New_York'),
                'deliveredAt' => $status === TrackingStatus::DELIVERED ? $delivered : null,
                'signedBy' => (string)($shipment['receivedBy'] ?? ''),
                'events' => $events,
            ]);
        }

        return $out;
    }

    private function event(array $entry): TrackingEvent
    {
        $concise = (string)($entry['conciseStatus'] ?? '');

        // isException outranks the words: "In Transit" with an exception flag is an exception.
        $status = match (true) {
            ($entry['isException'] ?? false) === true => TrackingStatus::EXCEPTION,
            $concise === '' => TrackingStatus::PRE_TRANSIT,
            strcasecmp($concise, 'Delivered') === 0 => TrackingStatus::DELIVERED,
            strcasecmp($concise, 'Out for Delivery') === 0 => TrackingStatus::OUT_FOR_DELIVERY,
            default => self::statusFromText($concise),
        };

        $reason = trim((string)($entry['reason'] ?? ''));

        return new TrackingEvent([
            'occurredAt' => self::parseDate(trim((string)($entry['referenceDate'] ?? '') . ' ' . (string)($entry['referenceTime'] ?? '')), 'America/New_York'),
            'status' => $status,
            'code' => (string)($entry['reasonCode'] ?? '') !== '' ? $concise . ':' . $entry['reasonCode'] : $concise,
            'description' => trim((string)($entry['expandedStatus'] ?? $concise) . ($reason !== '' ? ' — ' . $reason : '')),
            'location' => '',
        ]);
    }
}
