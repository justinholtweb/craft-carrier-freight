<?php

namespace justinholtweb\carrierfreight\carriers;

use Craft;
use justinholtweb\carrier\base\Accessorial;
use justinholtweb\carrier\base\ActionResult;
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
use justinholtweb\carrier\helpers\Units;
use justinholtweb\carrier\models\canonical\PickupRequest;
use justinholtweb\carrier\models\canonical\Rate;
use justinholtweb\carrier\models\canonical\ShipmentRef;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\canonical\TrackingEvent;
use justinholtweb\carrier\models\canonical\TrackingInfo;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrierfreight\auth\SessionTokenAuth;
use justinholtweb\carrierfreight\ebol\NmftaEbol;

/**
 * Old Dominion Freight Line.
 *
 * REST only: rating `POST /rating-service/v1.0/rates`, the NMFTA eBOL (v2.1.0) at
 * `POST /BOL/v3.1/eBOL/bol-external-per-standards`, pickups `POST /pickup/v3.0/create`, tracking
 * `POST /tracking/v2.0/shipment.track`. Production api.odfl.com, QA apiq.odfl.com. Sources: ODFL's
 * Rate Estimate (v1.0, 2026-08-21), Bill of Lading (v3.1, 2026-03-20), Pickup and Tracking API
 * development guides and the SOAP sunset page, via docs/research/ltl-freight.md §2.
 *
 * Authentication: `GET /auth/v1.0/token` with Basic auth of the ODFL.com username and password
 * returns a session token good for one hour, sent as a bearer token. ODFL answers bad credentials
 * (and disabled accounts) with **400** "Invalid Credentials", not 401.
 *
 * Vendor traps:
 * - **`freightClass` is typed integer in the rating guide and the sample sends 77**, yet the valid
 *   values include 77.5 and 92.5. This sends a JSON number — 70 as an int, 77.5 as a float. If QA
 *   rejects decimals, that is the known quirk to raise with API@odfl.com (UNVERIFIED).
 * - In the eBOL, `classification` is a *string*. Rating and eBOL also use different accessorial
 *   vocabularies: rating `HYD` is eBOL `LFTD`.
 * - Rating countries are ISO alpha-3 (`USA`); so is the eBOL `billTo` in ODFL's sample.
 * - `rateType` comes back uppercased with spaces removed (`DEFAULT`); matched accordingly.
 * - `rateEstimateNumber` (the quote number) is returned only for a single serviceType+rateType
 *   with `referenceNumberRequested: true`; it is `0` otherwise. This rates each service in its own
 *   request so every rate has one.
 * - A rate type that cannot be priced comes back with `totalCharge: null` and
 *   `errorMessages[]`, inside a successful response. The field table spells it `errorsMessages`;
 *   both are read.
 * - `CA` (call for appointment) cannot be combined with `ARN` (arrival notice); with both asked
 *   for, ARN is dropped and the merchant warned.
 * - `referenceNumbers.pro` is a string in the field table but a number in the sample.
 * - Tracking business errors (404 "No results found…") arrive **inside an HTTP 200**: `ok` is
 *   checked before anything else.
 * - All weights are integer pounds; dimensions integer inches.
 *
 * Quote numbers: ODFL quotes are referenced on the eBOL as `referenceNumbers.quoteId`, so
 * `createShipment()` quotes the shipped service again and cites that number (see
 * `FreightCarrier::quoteForBol()`); `options['quoteId']` skips the re-quote.
 *
 * **UNVERIFIED**:
 * - The token's JSON key (the guide does not show it): `sessionToken`, `access_token`, `token`,
 *   `data.sessionToken` and a bare string body are all accepted.
 * - The eBOL party shape (see `NmftaEbol`).
 * - The void: `deleteBolPro?proNumber=` is documented with "no body"; the HTTP method is not
 *   stated. This sends DELETE.
 * - Pickup `shipments[]` item fields: this sends `{proNumber}` per PRO being collected, or an empty
 *   list for a general pickup. Also which of `/update` and `/modify` is live (unused here).
 * - Rate limits are not documented.
 * - The public tracking page URL.
 */
class OdflCarrier extends FreightCarrier
{
    /** Ours => ODFL `serviceType`. */
    private const SERVICES = [
        'standard' => 'LTL',
        'guaranteed-pm' => 'Guaranteed by 5pm',
        'guaranteed-noon' => 'Guaranteed by Noon',
    ];

    protected const RATE_ACCESSORIALS = [
        Accessorial::LIFTGATE_PICKUP => 'HYO',
        Accessorial::LIFTGATE_DELIVERY => 'HYD',
        Accessorial::RESIDENTIAL_PICKUP => 'RPC',
        Accessorial::RESIDENTIAL_DELIVERY => 'RDC',
        Accessorial::INSIDE_DELIVERY => 'IDC',
        Accessorial::LIMITED_ACCESS_PICKUP => 'LPC',
        Accessorial::LIMITED_ACCESS_DELIVERY => 'LDC',
        Accessorial::APPOINTMENT => 'CA',
        Accessorial::NOTIFY => 'ARN',
        Accessorial::HAZMAT => '',
    ];

    protected const BOL_ACCESSORIALS = NmftaEbol::ACCESSORIALS;

    protected const REQUOTE_FOR_BOL = true;

    private const RATE_PACKAGING = [
        'pallet' => 'SKID',
        'skid' => 'SKID',
        'crate' => 'CRT',
        'carton' => 'CTN',
        'box' => 'BOX',
        'drum' => 'DRUM',
        'tote' => 'TOTE',
    ];

    private const STATUS = [
        'Pickup Requested' => TrackingStatus::PRE_TRANSIT,
        'Pickup Rescheduled' => TrackingStatus::PRE_TRANSIT,
        'Pickup Completed' => TrackingStatus::IN_TRANSIT,
        'In Transit' => TrackingStatus::IN_TRANSIT,
        'Received At Dock' => TrackingStatus::IN_TRANSIT,
        'Agent Handoff' => TrackingStatus::IN_TRANSIT,
        'Interline Handoff' => TrackingStatus::IN_TRANSIT,
        'Appointment Set' => TrackingStatus::IN_TRANSIT,
        'Appointment Confirmed' => TrackingStatus::IN_TRANSIT,
        'Appointment Cancelled' => TrackingStatus::IN_TRANSIT,
        'Appointment Rescheduled' => TrackingStatus::IN_TRANSIT,
        'Out for Delivery' => TrackingStatus::OUT_FOR_DELIVERY,
        'Delivered' => TrackingStatus::DELIVERED,
        'Delivery Confirmed' => TrackingStatus::DELIVERED,
        'Delayed' => TrackingStatus::EXCEPTION,
        'Appointment Attempted' => TrackingStatus::EXCEPTION,
        'Returned To Dock' => TrackingStatus::EXCEPTION,
        'Pickup Cancelled' => TrackingStatus::CANCELLED,
    ];

    public static function handle(): string
    {
        return 'freight-odfl';
    }

    public static function displayName(): string
    {
        return 'Old Dominion';
    }

    public static function description(): string
    {
        return Craft::t('carrier', 'LTL quotes, NMFTA electronic bills of lading, pickups and PRO tracking from Old Dominion Freight Line.');
    }

    public static function setupUrl(): ?string
    {
        return 'https://www.odfl.com/us/en/resources/shipping-api-integrations.html';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Feature::FREIGHT, Feature::RATES, Feature::LABELS, Feature::CANCEL, Feature::TRACKING, Feature::PICKUPS)
            ->labelFormats(LabelFormat::PDF_LETTER)
            ->originCountries('US', 'CA')
            ->accessorials(...static::supportedAccessorials())
            ->withSandbox();
    }

    public static function settingsFields(): array
    {
        return array_merge([
            Field::environment([
                'instructions' => 'Sandbox uses ODFL’s QA host (apiq.odfl.com). Ask API@odfl.com for QA access.',
            ]),
            Field::text('username', 'ODFL.com username', ['required' => true]),
            Field::secret('password', 'ODFL.com password', ['required' => true]),
            Field::text('accountNumber', 'ODFL account number', [
                'instructions' => 'Your bill-to account, for contract pricing. Digits only.',
            ]),
        ], static::freightFields());
    }

    public static function services(): array
    {
        return [
            'standard' => ['name' => 'Old Dominion LTL', 'international' => false, 'points' => false],
            'guaranteed-pm' => ['name' => 'Old Dominion Guaranteed by 5 PM', 'international' => false, 'points' => false],
            'guaranteed-noon' => ['name' => 'Old Dominion Guaranteed by Noon', 'international' => false, 'points' => false],
        ];
    }

    /** UNVERIFIED: ODFL's public trace page. */
    public static function trackingUrl(string $trackingNumber): ?string
    {
        return 'https://www.odfl.com/us/en/tools/trace-track-ltl-freight/trace.html?proNumbers=' . rawurlencode($trackingNumber);
    }

    private function host(): string
    {
        return $this->isSandbox() ? 'https://apiq.odfl.com' : 'https://api.odfl.com';
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new SessionTokenAuth(
            static function(Connection $c) {
                $host = $c->getSetting('environment', 'production') === 'sandbox' ? 'https://apiq.odfl.com' : 'https://api.odfl.com';

                return ['GET', $host . '/auth/v1.0/token', ['headers' => [
                    'Authorization' => 'Basic ' . base64_encode(SessionTokenAuth::settingOf($c, 'username') . ':' . SessionTokenAuth::settingOf($c, 'password')),
                    'Accept' => 'application/json',
                ]]];
            },
            ['username', 'password'],
            ['sessionToken', 'access_token', 'token', 'data.sessionToken', 'data.token'],
            3600,
        );
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri($this->host())
            ->setDefaultHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json']);
    }

    protected function probe(): HealthResult
    {
        $auth = $this->auth();

        if (!$auth instanceof SessionTokenAuth || $auth->accessToken() === null) {
            return HealthResult::fail(
                Craft::t('carrier', 'ODFL did not issue a session token for this username and password.'),
                [Craft::t('carrier', 'Use your ODFL.com login. ODFL answers “Invalid Credentials” for disabled accounts too. QA access is separate: email API@odfl.com.')],
            );
        }

        return HealthResult::pass(Craft::t('carrier', 'Signed in to Old Dominion as {user}.', ['user' => (string)$this->setting('username')]), [
            'Host' => $this->isSandbox() ? 'apiq.odfl.com (QA)' : 'api.odfl.com',
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

        $warnings = [];
        $accessorials = self::codesFor($request, static::RATE_ACCESSORIALS);

        if (in_array('CA', $accessorials, true) && in_array('ARN', $accessorials, true)) {
            $accessorials = array_values(array_diff($accessorials, ['ARN']));
            $warnings[] = Craft::t('carrier', 'Old Dominion cannot combine a delivery appointment with an arrival notice; the notice was dropped.');
        }

        $items = [];

        foreach ($units as $unit) {
            $item = [
                'freightClass' => self::classNumber($unit['class']),
                'handlingUnitCount' => 1,
                'handlingUnitType' => self::packagingCode($unit['packaging'], self::RATE_PACKAGING),
                'weight' => $unit['weightLb'],
                'hazardous' => $unit['hazmat'],
                'stackable' => false,
            ];

            if ($unit['hasDims']) {
                $item += ['length' => $unit['lengthIn'], 'width' => $unit['widthIn'], 'height' => $unit['heightIn'], 'dimensionUnitType' => 'IN'];
            }

            if ($unit['nmfcItem'] !== '') {
                $item['nmfc'] = (int)$unit['nmfcItem'];

                if ($unit['nmfcSub'] !== '') {
                    $item['nmfcSub'] = (int)$unit['nmfcSub'];
                }
            }

            $items[] = $item;
        }

        $base = [
            'referenceNumberRequested' => true,
            'movementDirection' => 'Outbound',
            'freightTerms' => 'Prepaid',
            'pickupDate' => self::shipDate($request)->format('Y-m-d') . 'T00:00:00.000Z',
            'shipper' => ['zipCode' => self::postcode($request->shipFrom), 'country' => self::iso3($request->shipFrom->countryCode)],
            'consignee' => ['zipCode' => self::postcode($request->shipTo), 'country' => self::iso3($request->shipTo->countryCode)],
            'totalWeight' => self::totalWeightLb($units),
            'weightUnitType' => 'LB',
            'shipmentItems' => $items,
        ];

        $account = preg_replace('/\D/', '', (string)$this->setting('accountNumber', ''));

        if ($account !== '') {
            $base['billToAccount'] = (int)$account;
        }

        if ($accessorials !== []) {
            $base['accessorialCodes'] = $accessorials;
        }

        $codes = $request->serviceCode !== '' ? [$request->serviceCode] : array_keys(self::SERVICES);
        $rates = [];
        $errors = [];

        // One request per service: ODFL only issues a quote number for a single serviceType.
        foreach ($codes as $code) {
            if (!isset(self::SERVICES[$code])) {
                $errors[] = Craft::t('carrier', 'Unknown Old Dominion service “{code}”.', ['code' => $code]);
                continue;
            }

            $response = $this->transport()->post('rating-service/v1.0/rates', $base + [
                'requestedRates' => [['serviceType' => self::SERVICES[$code], 'rateType' => 'Default']],
            ]);

            if (!$response->ok() || $response->at('success') === false) {
                $errors[] = $this->rateError($response, self::firstMessage($response->json_()));
                continue;
            }

            $quote = $response->at('rateEstimateNumber');
            $quote = is_scalar($quote) && (string)$quote !== '0' ? (string)$quote : '';
            $days = $response->at('serviceDays.0.days');

            foreach ((array)$response->at('requestedRates', []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $amount = self::money($entry['totalCharge'] ?? null);

                if ($amount === null || $amount <= 0) {
                    $message = self::firstMessage($entry);

                    if ($message !== '') {
                        $warnings[] = $message;
                    }

                    continue;
                }

                $serviceType = strtolower(str_replace(' ', '', (string)($entry['serviceType'] ?? self::SERVICES[$code])));
                $ours = $code;

                foreach (self::SERVICES as $candidate => $odfl) {
                    if (strtolower(str_replace(' ', '', $odfl)) === $serviceType) {
                        $ours = $candidate;
                    }
                }

                $rates[] = new Rate([
                    'serviceCode' => $ours,
                    'serviceName' => self::services()[$ours]['name'],
                    'amount' => $amount,
                    'currency' => 'USD',
                    'transitDays' => is_numeric($days) ? (int)$days : null,
                    'quoteId' => $quote,
                    'notes' => array_values(array_filter(array_map('strval', (array)($entry['informationMessages'] ?? [])))),
                ]);
            }
        }

        if ($rates === [] && $errors !== []) {
            return RateResult::failed($errors[0]);
        }

        return RateResult::ok($rates, $warnings);
    }

    private static function firstMessage(array $data): string
    {
        foreach (['errorMessages', 'errorsMessages', 'errors'] as $key) {
            $list = $data[$key] ?? null;

            if (is_array($list) && $list !== []) {
                $first = reset($list);

                return is_array($first) ? (string)($first['message'] ?? json_encode($first)) : (string)$first;
            }
        }

        return (string)($data['message'] ?? '');
    }

    // =========================================================================
    // Bill of lading, void
    // =========================================================================

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        if (!isset(self::SERVICES[$request->serviceCode])) {
            return ShipmentResult::rejected(Craft::t('carrier', 'Unknown Old Dominion service “{code}”.', ['code' => $request->serviceCode]));
        }

        if ($request->serviceCode !== 'standard') {
            // Guaranteed service on the eBOL is an accessorial (GTD_PM / GTD_NOON), not a field.
            $request = clone $request;
            $request->options['ebolExtraCodes'] = [$request->serviceCode === 'guaranteed-noon' ? 'GTD_NOON' : 'GTD_PM'];
        }

        return $this->createEbolShipment(
            $request,
            new NmftaEbol(version: '2.1.0', iso3: true, palletCode: 'PLT', quoteField: 'quoteId', labelFormats: [LabelFormat::PDF_LETTER => 'Avery']),
            'BOL/v3.1/eBOL/bol-external-per-standards',
        );
    }

    public function cancelShipment(ShipmentRef $shipment): ActionResult
    {
        $pro = (string)($shipment->data['pro'] ?? $shipment->trackingNumbers[0] ?? '');

        if ($pro === '') {
            return ActionResult::failed(Craft::t('carrier', 'This shipment has no PRO number to void.'));
        }

        $response = $this->transport()->delete('BOL/v3.1/eBOL/deleteBolPro', ['query' => ['proNumber' => $pro]]);

        if (!$response->ok()) {
            return ActionResult::failed($this->rateError($response, (string)$response->at('messageStatus.message', '')));
        }

        if (strtoupper((string)$response->at('messageStatus.status', '')) === 'FAIL') {
            return ActionResult::failed($this->redactSecrets((string)$response->at('messageStatus.message', 'Old Dominion refused the void.')));
        }

        return ActionResult::ok($pro);
    }

    // =========================================================================
    // Pickups
    // =========================================================================

    public function requestPickup(PickupRequest $request): ActionResult
    {
        $from = $request->address ?? $this->connection->getShipFromAddress();

        if ($from === null) {
            return ActionResult::failed(Craft::t('carrier', 'A pickup needs an address.'));
        }

        $person = [
            'firstName' => mb_substr(explode(' ', trim($from->contactName()))[0] ?? '', 0, 15),
            'telephoneNumber' => self::usPhone($from->phone),
            'companyName' => mb_substr($from->company !== '' ? $from->company : $from->contactName(), 0, 33),
            'streetAddressOne' => mb_substr($from->streetLine(), 0, 33),
            'city' => mb_substr($from->city, 0, 24),
            'state' => $from->region,
            'zipCode' => self::postcode($from),
        ];

        $body = [
            'pickupDate' => ($request->date ?? new \DateTime('tomorrow'))->format('Y-m-d'),
            'shipper' => array_filter($person),
            'requester' => array_filter($person + ['email' => $from->email]),
            'shipments' => array_map(static fn($pro) => ['proNumber' => (string)$pro], $request->trackingNumbers),
        ];

        $response = $this->transport()->post('pickup/v3.0/create', $body);

        if (!$response->ok()) {
            return ActionResult::failed($this->rateError($response));
        }

        $number = $response->at('response.pickupNumber') ?? $response->at('pickupNumber');

        if (!is_scalar($number) || (string)$number === '' || (string)$number === '0') {
            return ActionResult::failed($this->redactSecrets(self::firstMessage($response->json_()) ?: Craft::t('carrier', 'Old Dominion answered without a pickup number.')));
        }

        return ActionResult::ok((string)$number);
    }

    // =========================================================================
    // Tracking
    // =========================================================================

    public function track(array $trackingNumbers): array
    {
        $out = [];

        foreach ($trackingNumbers as $number) {
            $response = $this->transport()->post('tracking/v2.0/shipment.track', [
                'referenceType' => 'PRO',
                'referenceNumber' => (string)$number,
            ]);

            if (!$response->ok()) {
                if ($response->status === 404) {
                    $out[] = new TrackingInfo(['trackingNumber' => (string)$number, 'notFound' => true]);
                }

                continue;
            }

            // Business errors ride inside a 200.
            $trace = $response->at('traceInfo.0');

            if ($response->at('ok') === false || !is_array($trace)) {
                $out[] = new TrackingInfo(['trackingNumber' => (string)$number, 'notFound' => true]);
                continue;
            }

            $events = [];

            foreach ((array)($trace['trackTraceDetail'] ?? []) as $detail) {
                if (!is_array($detail)) {
                    continue;
                }

                $code = (string)($detail['status'] ?? '');

                $events[] = new TrackingEvent([
                    'occurredAt' => self::parseDate((string)($detail['dateTime'] ?? ''), 'America/New_York'),
                    'status' => self::mapStatus($code, self::STATUS, self::statusFromText($code . ' ' . ($detail['desc'] ?? ''))),
                    'code' => $code,
                    'description' => (string)($detail['desc'] ?? $code),
                    'location' => self::place($detail['city'] ?? '', $detail['state'] ?? ''),
                ]);
            }

            usort($events, static fn(TrackingEvent $a, TrackingEvent $b) => ($b->occurredAt?->getTimestamp() ?? 0) <=> ($a->occurredAt?->getTimestamp() ?? 0));
            $latest = $events[0] ?? null;
            $status = $latest?->status ?? TrackingStatus::PRE_TRANSIT;

            $out[] = new TrackingInfo([
                'trackingNumber' => (string)$number,
                'status' => $status,
                'statusText' => $latest?->description ?? TrackingStatus::label($status),
                'estimatedDelivery' => self::parseDate((string)($trace['updatedEta'] ?? $trace['standardEta'] ?? ''), 'America/New_York'),
                'deliveredAt' => $status === TrackingStatus::DELIVERED ? $latest?->occurredAt : null,
                'signedBy' => (string)($trace['deliverySign'] ?? ''),
                'events' => $events,
            ]);
        }

        return $out;
    }
}
