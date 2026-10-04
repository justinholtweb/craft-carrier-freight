<?php

namespace justinholtweb\carrierfreight\carriers;

use Craft;
use justinholtweb\carrier\base\Accessorial;
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
use justinholtweb\carrierfreight\ebol\NmftaEbol;

/**
 * SAIA LTL Freight.
 *
 * Rating `POST /rate-quote/webservice/ratequote/customer-api`, the NMFTA eBOL (v2.0.1) at
 * `POST /nmfta/bol/v2.0.1/app/`, tracking `GET /customer-api/webservice/track/pro-numbers/{pro}`,
 * on SAIA's Azure API Management gateway: production api.saia.com, pilot
 * saiapilotapi.azure-api.net. Sources: the prod and pilot developer portals' public metadata
 * (APIs, operations, schemas) and a Shipwell setup article for the key header names, via
 * docs/research/ltl-freight.md §6.
 *
 * ##############################################################################################
 * **UNVERIFIED — READ THIS FIRST.** SAIA does not publish the REST *response* schemas for rating
 * or tracking. Both parsers here are written from the **legacy SOAP field names** (`QuoteNumber`,
 * `TotalInvoice`, `StandardServiceDays`, `EstimatedDeliveryDate`, `Code`/`Message`) in camelCase
 * and PascalCase, looked for at the top level and under a few plausible wrappers. Tracking is
 * mapped from whatever status words it finds. Capture real responses from the pilot gateway and
 * replace these parsers before relying on SAIA rates at checkout. Until then a rate that cannot
 * be read is reported as "no rate", never invented.
 * ##############################################################################################
 *
 * Authentication: **four products, four subscription keys, four header names** —
 * `RQ-Key` (rate quote), `Tracking-Key` (tracking), `Ocp-Apim-Subscription-Key` (NMFTA eBOL) and
 * `CA-Key` (imaging, unused). Each is a separate setting. A Saia Secure (saiasecure.com) username
 * and password are also required: **in the JSON body** for rating (`userID`, `password`), and as
 * Basic auth for tracking (per Shipwell; UNVERIFIED). The body credentials are why every
 * configured secret is redacted out of the request log by value.
 *
 * Vendor traps:
 * - REST rating sends `class` as a **JSON number** (77.5); the eBOL's `classification` is a string.
 * - The eBOL's `requestedPickupDate` "does not serve as a Pickup Request", and there is **no
 *   public REST pickup API** in either portal — pickups are not offered.
 * - `isTest: true` on the eBOL validates without creating anything.
 * - The quote number must be cited on the BOL for the quoted price to be honoured (volume quote
 *   numbers start with `E`), so `createShipment()` re-quotes and cites it as NMFTA
 *   `referenceNumbers.quoteId` (field name UNVERIFIED); `options['quoteId']` skips the re-quote.
 * - APIM-level failures look like `{"statusCode":401,"message":"Access denied due to missing
 *   subscription key…"}`, which `Response::errorMessage()` already reads.
 *
 * **UNVERIFIED**, besides the response shapes above:
 * - **REST rating accessorial codes are not published** (one pilot example shows `RDEL` for
 *   residential delivery). Because nothing could be priced reliably, **no accessorials are
 *   offered**: a liftgate on the BOL that the quote never priced is a surprise invoice. A
 *   hazmat handling unit still gets NMFTA `HAZ` on the BOL.
 * - The NMFTA handling-unit code for a pallet: the research lists `PAT` for SAIA (Estes'
 *   swagger lists `PLT`). This sends `PAT`.
 * - The account-number field for customer pricing in the REST rating body (not sent).
 * - `details[].units` as the handling-unit count (one row per unit is sent with `units: 1`).
 * - The eBOL party shape (see `NmftaEbol`).
 * - The public tracking page URL.
 */
class SaiaCarrier extends FreightCarrier
{
    /** Deliberately empty: SAIA's REST rating codes are unpublished. See the class docblock. */
    protected const RATE_ACCESSORIALS = [];

    /** Only used for hazmat handling units, which add HAZ whether or not it was asked for. */
    protected const BOL_ACCESSORIALS = [
        Accessorial::HAZMAT => 'HAZ',
    ];

    protected const REQUOTE_FOR_BOL = true;

    /** Wrappers the REST rating answer might nest the legacy fields under. */
    private const RATE_ROOTS = ['', 'rateQuote', 'RateQuote', 'data', 'result', 'CreateResult', 'createResult', 'Body.CreateResponse.CreateResult'];

    public static function handle(): string
    {
        return 'freight-saia';
    }

    public static function displayName(): string
    {
        return 'SAIA';
    }

    public static function description(): string
    {
        return Craft::t('carrier', 'LTL quotes, NMFTA electronic bills of lading with labels, and PRO tracking from SAIA. Rating and tracking responses are unverified.');
    }

    public static function setupUrl(): ?string
    {
        return 'https://saiaprodapi.developer.azure-api.net/';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Feature::FREIGHT, Feature::RATES, Feature::LABELS, Feature::TRACKING)
            ->labelFormats(LabelFormat::PDF_LETTER, LabelFormat::PDF_4X6)
            ->originCountries('US')
            ->accessorials(...static::supportedAccessorials())
            ->withSandbox();
    }

    public static function settingsFields(): array
    {
        return array_merge([
            Field::environment([
                'instructions' => 'Sandbox uses SAIA’s pilot gateway (saiapilotapi.azure-api.net), which has its own subscription keys.',
            ]),
            Field::heading('Saia Secure login', 'Your saiasecure.com account. Sent in the body of rate requests and as Basic auth for tracking.'),
            Field::text('username', 'Saia Secure username', ['required' => true]),
            Field::secret('password', 'Saia Secure password', ['required' => true]),
            Field::heading('Subscription keys', 'One per product, from the developer portal: Products → subscribe → primary key.'),
            Field::secret('rateQuoteKey', 'Rate Quote key (RQ-Key)', ['required' => true]),
            Field::secret('trackingKey', 'Customer Tracking REST key (Tracking-Key)', ['required' => true]),
            Field::secret('bolKey', 'Bill of Lading key (Ocp-Apim-Subscription-Key)', ['required' => true]),
        ], static::freightFields());
    }

    public static function services(): array
    {
        return [
            'standard' => ['name' => 'SAIA LTL Standard', 'international' => false, 'points' => false],
        ];
    }

    /** UNVERIFIED: SAIA's public tracking page. */
    public static function trackingUrl(string $trackingNumber): ?string
    {
        return 'https://www.saia.com/track/details;pro=' . rawurlencode($trackingNumber);
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri($this->isSandbox() ? 'https://saiapilotapi.azure-api.net' : 'https://api.saia.com')
            ->setDefaultHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json']);
    }

    private function keyHeader(string $header, string $setting): array
    {
        return ['headers' => [$header => (string)$this->setting($setting, '')]];
    }

    protected function probe(): HealthResult
    {
        $response = $this->transport()->get('rate-quote/health', [], $this->keyHeader('RQ-Key', 'rateQuoteKey'));

        if ($response->unauthorized()) {
            return HealthResult::fail($this->redactSecrets($response->errorMessage()), [
                Craft::t('carrier', 'Each SAIA product has its own key. Check the Rate Quote key belongs to this environment (pilot keys do not work in production).'),
            ]);
        }

        if (!$response->ok()) {
            return HealthResult::fail($this->redactSecrets($response->errorMessage()));
        }

        return HealthResult::pass(Craft::t('carrier', 'SAIA’s rate-quote gateway accepted the key.'), [
            'Gateway' => $this->isSandbox() ? 'saiapilotapi.azure-api.net (pilot)' : 'api.saia.com',
            'Note' => 'Only the Rate Quote key is checked here; the tracking and BOL keys are checked on first use.',
        ]);
    }

    // =========================================================================
    // Rates — response shape UNVERIFIED
    // =========================================================================

    public function rate(ShipmentRequest $request): RateResult
    {
        $units = $this->handlingUnits($request, $error);

        if ($error !== null) {
            return RateResult::failed($error);
        }

        if ($request->serviceCode !== '' && $request->serviceCode !== 'standard') {
            return RateResult::failed(Craft::t('carrier', 'Unknown SAIA service “{code}”.', ['code' => $request->serviceCode]));
        }

        $details = [];

        foreach ($units as $unit) {
            $detail = ['weight' => $unit['weightLb'], 'class' => self::classNumber($unit['class']), 'units' => 1];

            if ($unit['hasDims']) {
                $detail = ['length' => $unit['lengthIn'], 'width' => $unit['widthIn'], 'height' => $unit['heightIn']] + $detail;
            }

            $details[] = $detail;
        }

        $body = [
            'userID' => (string)$this->setting('username', ''),
            'password' => (string)$this->setting('password', ''),
            'payer' => 'Shipper',
            'origin' => $this->location($request->shipFrom),
            'destination' => $this->location($request->shipTo),
            'weightUnits' => 'LBS',
            'measurementUnit' => 'IN',
            'details' => $details,
            'accessorials' => ['codes' => []],
        ];

        $response = $this->transport()->post('rate-quote/webservice/ratequote/customer-api', $body, $this->keyHeader('RQ-Key', 'rateQuoteKey'));

        if (!$response->ok()) {
            return RateResult::failed($this->rateError($response));
        }

        $root = $this->rateRoot($response->json_());

        if ($root === null) {
            return RateResult::ok([]);
        }

        $amount = self::money(self::pick($root, ['totalInvoice', 'TotalInvoice', 'totalCharge', 'TotalCharge']));

        if ($amount === null || $amount <= 0) {
            $message = (string)(self::pick($root, ['message', 'Message', 'fault', 'Fault']) ?? '');

            return $message !== '' ? RateResult::failed($this->redactSecrets($message)) : RateResult::ok([]);
        }

        $days = self::pick($root, ['standardServiceDays', 'StandardServiceDays', 'serviceDays', 'ServiceDays']);
        $eta = self::pick($root, ['estimatedDeliveryDate', 'EstimatedDeliveryDate']);

        return RateResult::ok([new Rate([
            'serviceCode' => 'standard',
            'serviceName' => self::services()['standard']['name'],
            'amount' => $amount,
            'currency' => 'USD',
            'transitDays' => is_numeric($days) ? (int)$days : null,
            'estimatedDelivery' => is_string($eta) && $eta !== '' ? $eta : null,
            'quoteId' => (string)(self::pick($root, ['quoteNumber', 'QuoteNumber']) ?? ''),
            'notes' => ['SAIA rate parsed from an unverified response shape.'],
        ])]);
    }

    /** The object holding the legacy fields, wherever the REST answer put it. */
    private function rateRoot(array $json): ?array
    {
        foreach (self::RATE_ROOTS as $path) {
            $node = $json;

            foreach ($path === '' ? [] : explode('.', $path) as $segment) {
                $node = is_array($node) ? ($node[$segment] ?? null) : null;
            }

            if (is_array($node) && self::pick($node, ['totalInvoice', 'TotalInvoice', 'totalCharge', 'TotalCharge', 'message', 'Message', 'fault', 'Fault']) !== null) {
                return $node;
            }
        }

        return null;
    }

    private static function pick(array $node, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $node) && $node[$key] !== null && $node[$key] !== '') {
                return $node[$key];
            }
        }

        return null;
    }

    private function location(?\justinholtweb\carrier\models\canonical\Address $address): array
    {
        return [
            'city' => strtoupper($address?->city ?? ''),
            'state' => $address?->region ?? '',
            'zipcode' => $address ? self::postcode($address) : '',
        ];
    }

    // =========================================================================
    // Bill of lading
    // =========================================================================

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        if ($request->serviceCode !== 'standard') {
            return ShipmentResult::rejected(Craft::t('carrier', 'Unknown SAIA service “{code}”.', ['code' => $request->serviceCode]));
        }

        return $this->createEbolShipment(
            $request,
            new NmftaEbol(
                version: '2.0.1',
                iso3: false,
                palletCode: 'PAT',
                quoteField: 'quoteId',
                labelFormats: [LabelFormat::PDF_LETTER => 'Avery', LabelFormat::PDF_4X6 => 'Zebra'],
            ),
            'nmfta/bol/v2.0.1/app/',
            $this->keyHeader('Ocp-Apim-Subscription-Key', 'bolKey'),
        );
    }

    // =========================================================================
    // Tracking — response shape UNVERIFIED
    // =========================================================================

    public function track(array $trackingNumbers): array
    {
        $out = [];
        $options = ['headers' => [
            'Tracking-Key' => (string)$this->setting('trackingKey', ''),
            'Authorization' => 'Basic ' . base64_encode($this->setting('username', '') . ':' . $this->setting('password', '')),
        ]];

        foreach ($trackingNumbers as $number) {
            $response = $this->transport()->get('customer-api/webservice/track/pro-numbers/' . rawurlencode((string)$number), [], $options);

            if (!$response->ok()) {
                if ($response->status === 404) {
                    $out[] = new TrackingInfo(['trackingNumber' => (string)$number, 'notFound' => true]);
                }

                continue;
            }

            $json = $response->json_();
            $node = self::firstShipment($json);

            if ($node === null) {
                $out[] = new TrackingInfo(['trackingNumber' => (string)$number, 'notFound' => true]);
                continue;
            }

            $events = [];

            foreach (['history', 'History', 'events', 'Events', 'statusHistory', 'StatusHistory', 'activities', 'Activities'] as $key) {
                foreach ((array)($node[$key] ?? []) as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $text = (string)(self::pick($entry, ['description', 'Description', 'status', 'Status', 'activity', 'Activity']) ?? '');
                    $date = trim((string)(self::pick($entry, ['dateTime', 'DateTime', 'date', 'Date', 'activityDate', 'ActivityDate']) ?? '') . ' ' . (string)(self::pick($entry, ['time', 'Time']) ?? ''));

                    $events[] = new TrackingEvent([
                        'occurredAt' => self::parseDate($date, 'America/New_York'),
                        'status' => self::statusFromText($text),
                        'code' => (string)(self::pick($entry, ['code', 'Code', 'statusCode', 'StatusCode']) ?? ''),
                        'description' => $text,
                        'location' => self::place((string)(self::pick($entry, ['city', 'City']) ?? ''), (string)(self::pick($entry, ['state', 'State']) ?? '')),
                    ]);
                }
            }

            $text = (string)(self::pick($node, ['status', 'Status', 'currentStatus', 'CurrentStatus', 'shipmentStatus', 'ShipmentStatus', 'statusDescription', 'StatusDescription']) ?? '');

            if (is_array($node['status'] ?? null)) {
                $text = (string)(self::pick($node['status'], ['description', 'Description', 'status', 'Status']) ?? '');
            }

            $status = $text !== ''
                ? self::statusFromText($text)
                : ($events[0]->status ?? TrackingStatus::PRE_TRANSIT);
            $delivered = self::pick($node, ['deliveryDate', 'DeliveryDate', 'deliveredDate', 'DeliveredDate']);

            $out[] = new TrackingInfo([
                'trackingNumber' => (string)$number,
                'status' => $status,
                'statusText' => $text !== '' ? $text : TrackingStatus::label($status),
                'deliveredAt' => $status === TrackingStatus::DELIVERED && is_string($delivered) ? self::parseDate($delivered, 'America/New_York') : null,
                'signedBy' => (string)(self::pick($node, ['signature', 'Signature', 'signedBy', 'SignedBy', 'receivedBy', 'ReceivedBy']) ?? ''),
                'events' => $events,
            ]);
        }

        return $out;
    }

    /** The shipment object, wherever it was put: the root, `data`, or the first of a list. */
    private static function firstShipment(array $json): ?array
    {
        foreach ([$json, $json['data'] ?? null, $json['shipment'] ?? null, $json['Shipment'] ?? null] as $candidate) {
            if (!is_array($candidate) || $candidate === []) {
                continue;
            }

            if (array_is_list($candidate)) {
                $candidate = $candidate[0] ?? null;

                if (!is_array($candidate)) {
                    continue;
                }
            }

            foreach (['shipments', 'Shipments'] as $listKey) {
                if (isset($candidate[$listKey][0]) && is_array($candidate[$listKey][0])) {
                    return $candidate[$listKey][0];
                }
            }

            if (self::pick($candidate, ['proNumber', 'ProNumber', 'pro', 'status', 'Status', 'currentStatus', 'CurrentStatus', 'history', 'History']) !== null) {
                return $candidate;
            }
        }

        return null;
    }
}
