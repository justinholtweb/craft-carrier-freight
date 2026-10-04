<?php

namespace justinholtweb\carrierfreight\carriers;

use Craft;
use justinholtweb\carrier\auth\OAuth2ClientCredentials;
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
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\models\canonical\PickupRequest;
use justinholtweb\carrier\models\canonical\Rate;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\canonical\TrackingEvent;
use justinholtweb\carrier\models\canonical\TrackingInfo;

/**
 * TForce Freight (formerly UPS Freight).
 *
 * Rating `POST /rating/getRate`, BOL `POST /shipping/bol/create`, pickups `POST /pickup/request`
 * and tracking `GET /track/pro/{pro}`, all on api.tforcefreight.com behind a Microsoft identity
 * platform (Azure AD) client-credentials token. Sources: TForce's Rating, Shipping, Pickup and
 * Tracking API user manuals (Rating manual dated 2026-07-06), via docs/research/ltl-freight.md §1.
 *
 * Vendor traps:
 * - **Unknown or misspelled properties reject the whole request.** Every body here is built from
 *   documented keys only, and nulls/empty strings/empty arrays are stripped before sending.
 * - The sandbox (CIE) is the *same host* with `?api-version=cie-v1`; production is `v1`. CIE
 *   negotiated rates are not your contract rates.
 * - The manuals swap two delivery codes: the Delivery Codes table says `LADL` = liftgate and
 *   `LIFD` = limited access, while the Rate Codes table, the examples and the pickup twin `LIFO`
 *   say the opposite. This uses **LIFD = liftgate delivery, LADL = limited access delivery**
 *   (UNVERIFIED — confirm in CIE).
 * - Residential is `shipFrom/shipTo.isResidential`, not a service code. The response itemises it
 *   as RESD/RESP.
 * - `class` is a string ("77.5"); charge values are strings ("1551.53").
 * - Pickup `/request` times are "HHMM"; the BOL's embedded pickup uses "HH:MM:SS" (not used here).
 * - The BOL create response says `timeInTransit.timeInTransit` where rating says `.value`.
 * - Label type 07 (4×6 thermal) is the only one that allows ZPL; type 01 is Avery sheets.
 * - 403 is a *quota* (resets after 300 s) and 429 a rate limit (60 s); both carry Retry-After,
 *   which the transport honours.
 *
 * Quote numbers: TForce returns `summary.quoteNumber` but the research found no BOL field that
 * cites one, so nothing is re-quoted at BOL time.
 *
 * No CANCEL: the manuals document cancelling a *pickup* (`DELETE /pickup/request/{n}`), not a BOL.
 *
 * **UNVERIFIED**:
 * - The BOL party objects. The manual names "name, email and phone on each party" but the street
 *   field is not shown; this sends `address.address1/2` (the pickup API's names) and
 *   `phone.number` (the hazmat contact's shape). The strict schema will say so loudly if wrong.
 * - The BOL `requestOptions` subset (`serviceCode`, `pickupDate`, `type`, `previewRate`).
 * - `references[]` is documented but its item shape is not, so the order reference is *not* sent.
 * - Pickup `requester` and `origin` field names beyond those listed, and the date format
 *   (YYYY-MM-DD, as in rating).
 * - The error body shape (the manuals only show `summary.responseStatus`).
 * - Negotiated (account) rates: the account linkage field is not shown; rates are whatever the
 *   API client's enrolment gives.
 * - The public tracking page URL.
 */
class TforceCarrier extends FreightCarrier
{
    private const TOKEN_URL = 'https://login.microsoftonline.com/ca4f5969-c10f-40d4-8127-e74b691f95de/oauth2/v2.0/token';

    private const SCOPE = 'https://tffproduction.onmicrosoft.com/f06cb173-a8e6-44ad-89a1-06c1070a1f62/.default';

    /** Our service codes => TForce's. */
    private const SERVICES = [
        'standard' => '308',
        'guaranteed' => '309',
        'accelerated' => '311',
    ];

    private const PICKUP_CODES = [
        Accessorial::LIFTGATE_PICKUP => 'LIFO',
        Accessorial::LIMITED_ACCESS_PICKUP => 'LAPU',
    ];

    private const DELIVERY_CODES = [
        Accessorial::LIFTGATE_DELIVERY => 'LIFD',
        Accessorial::INSIDE_DELIVERY => 'INDE',
        Accessorial::LIMITED_ACCESS_DELIVERY => 'LADL',
        Accessorial::NOTIFY => 'NTFN',
    ];

    /** TForce uses one vocabulary for rating, BOL and pickup. Empty = expressed as a flag. */
    protected const RATE_ACCESSORIALS = [
        Accessorial::LIFTGATE_PICKUP => 'LIFO',
        Accessorial::LIMITED_ACCESS_PICKUP => 'LAPU',
        Accessorial::LIFTGATE_DELIVERY => 'LIFD',
        Accessorial::INSIDE_DELIVERY => 'INDE',
        Accessorial::LIMITED_ACCESS_DELIVERY => 'LADL',
        Accessorial::NOTIFY => 'NTFN',
        Accessorial::RESIDENTIAL_PICKUP => '',
        Accessorial::RESIDENTIAL_DELIVERY => '',
        Accessorial::HAZMAT => '',
    ];

    protected const BOL_ACCESSORIALS = self::RATE_ACCESSORIALS;

    private const PACKAGING = [
        'pallet' => 'PLT',
        'skid' => 'SKD',
        'crate' => 'CRT',
        'carton' => 'CTN',
        'box' => 'BOX',
        'drum' => 'DRM',
        'bag' => 'BAG',
        'loose' => 'LOO',
        'tote' => 'OTH',
        'pail' => 'OTH',
        'roll' => 'OTH',
        'reel' => 'OTH',
        'bundle' => 'OTH',
    ];

    /** `currentStatus.details` milestones. */
    private const STATUS = [
        '004' => TrackingStatus::CANCELLED,
        '005' => TrackingStatus::IN_TRANSIT,
        '006' => TrackingStatus::OUT_FOR_DELIVERY,
        '011' => TrackingStatus::DELIVERED,
        '013' => TrackingStatus::EXCEPTION,
    ];

    public static function handle(): string
    {
        return 'freight-tforce';
    }

    public static function displayName(): string
    {
        return 'TForce Freight';
    }

    public static function description(): string
    {
        return Craft::t('carrier', 'LTL quotes, bills of lading with PDF or ZPL labels, pickups and PRO tracking from TForce Freight.');
    }

    public static function setupUrl(): ?string
    {
        return 'https://developer.tforcefreight.com/';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Feature::FREIGHT, Feature::RATES, Feature::LABELS, Feature::TRACKING, Feature::PICKUPS)
            ->labelFormats(LabelFormat::PDF_LETTER, LabelFormat::PDF_4X6, LabelFormat::ZPL)
            ->originCountries('US')
            ->accessorials(...static::supportedAccessorials())
            ->withSandbox();
    }

    public static function settingsFields(): array
    {
        return array_merge([
            Field::environment([
                'instructions' => 'Sandbox uses TForce’s Customer Integration Environment (same host, api-version cie-v1). CIE rates are not your contract rates.',
            ]),
            Field::text('clientId', 'Client ID', [
                'required' => true,
                'instructions' => 'developer.tforcefreight.com → Profile → Configure My Client.',
            ]),
            Field::secret('clientSecret', 'Client secret', [
                'required' => true,
                'instructions' => 'Shown only once when the client is created. Store it in an environment variable.',
            ]),
        ], static::freightFields());
    }

    public static function services(): array
    {
        return [
            'standard' => ['name' => 'TForce Freight LTL', 'international' => false, 'points' => false],
            'guaranteed' => ['name' => 'TForce Freight LTL Guaranteed', 'international' => false, 'points' => false],
            'accelerated' => ['name' => 'TForce Freight Accelerated Guaranteed', 'international' => false, 'points' => false],
        ];
    }

    /** UNVERIFIED: TForce's public tracking page. */
    public static function trackingUrl(string $trackingNumber): ?string
    {
        return 'https://www.tforcefreight.com/ltl/apps/Tracking?proNumbers=' . rawurlencode($trackingNumber);
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new OAuth2ClientCredentials(self::TOKEN_URL, self::SCOPE);
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri('https://api.tforcefreight.com')
            ->setDefaultHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json']);
    }

    private function apiVersion(): array
    {
        return ['api-version' => $this->isSandbox() ? 'cie-v1' : 'v1'];
    }

    protected function probe(): HealthResult
    {
        $auth = $this->auth();

        if (!$auth instanceof OAuth2ClientCredentials || $auth->accessToken() === null) {
            return HealthResult::fail(
                Craft::t('carrier', 'Microsoft’s identity platform did not issue a token for this client ID and secret.'),
                [Craft::t('carrier', 'Check the client ID and secret at developer.tforcefreight.com. A secret is shown only once; create a new one if in doubt.')],
            );
        }

        // A PRO that cannot exist: 404 proves the token is accepted by the tracking API.
        $response = $this->transport()->get('track/pro/000000000', $this->apiVersion());

        if ($response->unauthorized()) {
            return HealthResult::fail($this->redactSecrets($response->errorMessage()), [
                Craft::t('carrier', 'The token was issued but TForce refused it. Make sure your client is enrolled for the Tracking and Rating APIs.'),
            ]);
        }

        if ($response->status === 0 || $response->status >= 500) {
            return HealthResult::fail($this->redactSecrets($response->errorMessage()));
        }

        return HealthResult::pass(Craft::t('carrier', 'Connected to TForce Freight.'), [
            'Environment' => $this->isSandbox() ? 'Customer Integration Environment' : 'Production',
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

        $codes = $request->serviceCode !== '' ? [$request->serviceCode] : array_keys(self::SERVICES);
        $rates = [];
        $warnings = [];
        $errors = [];

        foreach ($codes as $code) {
            if (!isset(self::SERVICES[$code])) {
                $errors[] = Craft::t('carrier', 'Unknown TForce service “{code}”.', ['code' => $code]);
                continue;
            }

            $response = $this->transport()->post('rating/getRate', $this->shipmentBody($request, $units, self::SERVICES[$code]), ['query' => $this->apiVersion()]);

            if (!$response->ok()) {
                $errors[] = $this->rateError($response, (string)$response->at('summary.responseStatus.message', ''));
                continue;
            }

            if (strtoupper((string)$response->at('summary.responseStatus.code', '')) === 'NFO') {
                $warnings[] = (string)$response->at('summary.responseStatus.message', 'No rate found.');
                continue;
            }

            $quoteNumber = (string)$response->at('summary.quoteNumber', '');

            foreach ((array)$response->at('detail', []) as $detail) {
                if (!is_array($detail)) {
                    continue;
                }

                $amount = self::money($detail['shipmentCharges']['total']['value'] ?? null);

                if ($amount === null || $amount <= 0) {
                    continue;
                }

                $tforceCode = (string)($detail['service']['code'] ?? self::SERVICES[$code]);
                $ours = array_search($tforceCode, self::SERVICES, true) ?: $code;
                $transit = $detail['timeInTransit']['value'] ?? $detail['timeInTransit']['timeInTransit'] ?? null;

                $rates[] = new Rate([
                    'serviceCode' => $ours,
                    'serviceName' => self::services()[$ours]['name'] ?? $tforceCode,
                    'amount' => $amount,
                    'currency' => strtoupper((string)($detail['shipmentCharges']['total']['currency'] ?? 'USD')) ?: 'USD',
                    'transitDays' => is_numeric($transit) ? (int)$transit : null,
                    'quoteId' => $quoteNumber,
                    'notes' => array_values(array_filter(array_map(
                        static fn($alert) => is_array($alert) ? (string)($alert['message'] ?? '') : '',
                        (array)($detail['alerts'] ?? []),
                    ))),
                ]);
            }
        }

        if ($rates === [] && $errors !== []) {
            return RateResult::failed($errors[0]);
        }

        return RateResult::ok($rates, $warnings);
    }

    // =========================================================================
    // Bill of lading
    // =========================================================================

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        $units = $this->handlingUnits($request, $error);

        if ($error !== null) {
            return ShipmentResult::rejected($error);
        }

        $service = self::SERVICES[$request->serviceCode] ?? null;

        if ($service === null) {
            return ShipmentResult::rejected(Craft::t('carrier', 'Unknown TForce service “{code}”.', ['code' => $request->serviceCode]));
        }

        $format = $this->labelFormatFor($request);
        $body = $this->shipmentBody($request, $units, $service, true);

        $body['requestOptions'] = [
            'serviceCode' => $service,
            'pickupDate' => self::shipDate($request)->format('Y-m-d'),
            'type' => 'L',
            'previewRate' => false,
        ];

        $body['handlingUnitOne'] = [
            'quantity' => count($units),
            'typeCode' => self::packagingCode($units[0]['packaging'], self::PACKAGING),
        ];

        if ($request->instructions !== '') {
            $body['instructions'] = ['delivery' => mb_substr($request->instructions, 0, 250)];
        }

        $body['documents'] = ['image' => [
            ['type' => '20', 'format' => '01'],
            [
                'type' => '30',
                'format' => $format === LabelFormat::ZPL ? '02' : '01',
                'label' => [
                    'type' => $format === LabelFormat::PDF_LETTER ? '01' : '07',
                    'startPosition' => 1,
                    'numberOfStickers' => max(1, count($units)),
                ],
            ],
        ]];

        $response = $this->transport()->post('shipping/bol/create', $body, ['query' => $this->apiVersion()]);

        if (!$response->ok()) {
            return $this->failure($response, (string)$response->at('summary.responseStatus.message', ''));
        }

        $pro = trim((string)$response->at('detail.pro', ''));
        $bolId = (string)$response->at('detail.bolId', '');
        $labels = [];
        $bol = null;

        foreach ((array)$response->at('detail.documents.image', []) as $image) {
            if (!is_array($image)) {
                continue;
            }

            if ((string)($image['type'] ?? '') === '20') {
                $bol = self::document($image['data'] ?? null, LabelFormat::PDF_LETTER, 'bol', $pro);
            } else {
                $labelFormat = (string)($image['format'] ?? '') === '02' ? LabelFormat::ZPL : ($format === LabelFormat::ZPL ? LabelFormat::PDF_4X6 : $format);
                $labels[] = self::document($image['data'] ?? null, $labelFormat, 'label', $pro);
            }
        }

        return $this->bolResult($pro, $bolId, array_merge($labels, [$bol]), [
            'pro' => $pro,
            'bolId' => $bolId,
            'pickupConfirmation' => (string)$response->at('detail.pickup.transactionReference.confirmationNumber', ''),
        ]);
    }

    /**
     * The body rating and BOL share. With `$forBol`, parties gain the name, street, phone and email
     * the BOL needs — and rating never sees those keys, because TForce rejects any it does not
     * expect.
     */
    private function shipmentBody(ShipmentRequest $request, array $units, string $service, bool $forBol = false): array
    {
        $hazmat = self::anyHazmat($units);
        $commodities = [];
        $handlingUnits = [];
        $allDims = true;

        foreach ($units as $unit) {
            $packaging = self::packagingCode($unit['packaging'], self::PACKAGING);
            $commodity = [
                'class' => $unit['class'],
                'pieces' => $unit['pieces'],
                'weight' => ['weight' => $unit['weightLb'], 'weightUnit' => 'LBS'],
                'packagingType' => $packaging,
                'dangerousGoods' => $unit['hazmat'],
            ];

            if ($forBol) {
                $commodity['description'] = mb_substr($unit['description'], 0, 100);
            }

            if ($unit['nmfcItem'] !== '') {
                $commodity['nmfc'] = array_filter(['prime' => str_pad($unit['nmfcItem'], 6, '0', STR_PAD_LEFT), 'sub' => $unit['nmfcSub']], static fn($v) => $v !== '');
            }

            if ($unit['hasDims']) {
                $dimensions = ['length' => $unit['lengthIn'], 'width' => $unit['widthIn'], 'height' => $unit['heightIn'], 'unit' => 'IN'];
                $commodity['dimensions'] = $dimensions;
                $handlingUnits[] = [
                    'pieces' => 1,
                    'weight' => ['weight' => $unit['weightLb'], 'weightUnit' => 'LBS'],
                    'packagingType' => $packaging,
                    'dimensions' => $dimensions,
                ];
            } else {
                $allDims = false;
            }

            $commodities[] = $commodity;
        }

        $body = [
            'requestOptions' => [
                'serviceCode' => $service,
                'pickupDate' => self::shipDate($request)->format('Y-m-d'),
                'type' => 'L',
                'densityEligible' => false,
                'timeInTransit' => true,
                'quoteNumber' => true,
            ],
            'shipFrom' => $this->party($request->shipFrom, self::wants($request, Accessorial::RESIDENTIAL_PICKUP), $forBol),
            'shipTo' => $this->party($request->shipTo, self::wants($request, Accessorial::RESIDENTIAL_DELIVERY), $forBol),
            'payment' => [
                'payer' => ['address' => $this->location($request->shipFrom)],
                'billingCode' => '10',
            ],
            'commodities' => $commodities,
        ];

        if ($request->reference !== '' && !$forBol) {
            $body['requestOptions']['customerContext'] = mb_substr($request->reference, 0, 40);
        }

        $serviceOptions = array_filter([
            'pickup' => self::codesFor($request, self::PICKUP_CODES),
            'delivery' => self::codesFor($request, self::DELIVERY_CODES),
        ]);

        if ($forBol && $hazmat) {
            $serviceOptions['shipment'] = ['dangerousGoods' => array_filter([
                'name' => $request->shipFrom?->contactName() ?? '',
                'phone' => ['number' => self::usPhone($request->shipFrom?->phone ?? '')],
            ])];
        }

        if ($serviceOptions !== []) {
            $body['serviceOptions'] = $serviceOptions;
        }

        if ($allDims && $handlingUnits !== []) {
            $body['handlingUnits'] = $handlingUnits;
        }

        return $body;
    }

    private function party(?Address $address, bool $residential, bool $forBol): array
    {
        $party = ['address' => $this->location($address, $forBol)];

        if ($forBol && $address !== null) {
            $party = array_filter([
                'name' => mb_substr($address->company !== '' ? $address->company : $address->contactName(), 0, 35),
                'email' => $address->email,
                'phone' => self::usPhone($address->phone) !== '' ? ['number' => self::usPhone($address->phone)] : null,
            ]) + $party;
        }

        $party['isResidential'] = $residential;

        return $party;
    }

    private function location(?Address $address, bool $withStreet = false): array
    {
        if ($address === null) {
            return [];
        }

        $location = [];

        if ($withStreet) {
            $location['address1'] = mb_substr($address->streetLine(), 0, 35);

            if ($address->street2 !== '') {
                $location['address2'] = mb_substr($address->street2, 0, 35);
            }
        }

        return $location + array_filter([
            'city' => $address->city,
            'stateProvinceCode' => $address->region,
            'postalCode' => self::postcode($address),
            'country' => strtoupper($address->countryCode),
        ]);
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

        $date = $request->date ?? new \DateTime('tomorrow');
        $hhmm = static fn(string $time) => str_pad(preg_replace('/\D/', '', $time) ?: '0900', 4, '0', STR_PAD_LEFT);

        $body = [
            'pickup' => [
                'date' => $date->format('Y-m-d'),
                'time' => $hhmm($request->readyTime),
                'openTime' => $hhmm($request->readyTime),
                'closeTime' => $hhmm($request->closeTime),
            ],
            'requester' => array_filter([
                'name' => mb_substr($from->contactName(), 0, 35),
                'email' => $from->email,
                'phone' => self::usPhone($from->phone) !== '' ? ['number' => self::usPhone($from->phone)] : null,
                'thirdParty' => false,
            ], static fn($v) => $v !== '' && $v !== null),
            'origin' => array_filter([
                'name' => mb_substr($from->company !== '' ? $from->company : $from->contactName(), 0, 35),
                'address' => $this->location($from, true),
            ]),
            'lineItems' => [[
                'description' => $this->commodityDescription(),
                'weight' => \justinholtweb\carrier\helpers\Units::gramsToWholeLb(max(1, $request->totalWeightG)),
                'weightUnit' => 'LBS',
                'pieces' => max(1, $request->parcelCount),
                'packagingType' => 'PLT',
                'hazardous' => false,
            ]],
        ];

        $response = $this->transport()->post('pickup/request', $body, ['query' => $this->apiVersion()]);

        if (!$response->ok()) {
            return ActionResult::failed($this->rateError($response, (string)$response->at('summary.responseStatus.message', '')));
        }

        $confirmation = (string)($response->at('transactionReference.confirmationNumber')
            ?? $response->at('detail.transactionReference.confirmationNumber')
            ?? '');

        if ($confirmation === '') {
            return ActionResult::failed(Craft::t('carrier', 'TForce answered without a pickup confirmation number.'));
        }

        return ActionResult::ok($confirmation, null, [
            'originIsRural' => $response->at('originIsRural'),
        ]);
    }

    // =========================================================================
    // Tracking
    // =========================================================================

    public function track(array $trackingNumbers): array
    {
        $out = [];

        foreach ($trackingNumbers as $number) {
            $response = $this->transport()->get('track/pro/' . rawurlencode((string)$number), $this->apiVersion());

            if ($response->status === 404) {
                $out[] = new TrackingInfo(['trackingNumber' => (string)$number, 'notFound' => true]);
                continue;
            }

            if (!$response->ok()) {
                continue;
            }

            $detail = $response->at('detail.0');

            if (!is_array($detail)) {
                $out[] = new TrackingInfo(['trackingNumber' => (string)$number, 'notFound' => true]);
                continue;
            }

            $events = [];

            foreach ((array)($detail['events'] ?? []) as $event) {
                if (!is_array($event)) {
                    continue;
                }

                $description = (string)($event['description'] ?? '');
                $centre = $event['serviceCenter'] ?? '';

                // Events carry words only — no code in the manual's sample.
                $events[] = new TrackingEvent([
                    'occurredAt' => self::parseDate((string)($event['date'] ?? ''), 'America/New_York'),
                    'status' => self::statusFromText($description),
                    'code' => '',
                    'description' => $description,
                    'location' => is_array($centre) ? self::place($centre['city'] ?? $centre['name'] ?? '', $centre['state'] ?? $centre['stateProvinceCode'] ?? '') : (string)$centre,
                ]);
            }

            $current = (array)($detail['currentStatus'] ?? []);
            $milestone = (string)($current['details'] ?? '');
            $status = $milestone !== ''
                ? self::mapStatus($milestone, self::STATUS, $events === [] ? TrackingStatus::PRE_TRANSIT : TrackingStatus::IN_TRANSIT)
                : ($events === [] ? TrackingStatus::PRE_TRANSIT : self::statusFromText((string)($current['description'] ?? '')));

            $delivered = self::parseDate((string)($detail['delivery']['actual']['date'] ?? ''), 'America/New_York');

            $out[] = new TrackingInfo([
                'trackingNumber' => (string)$number,
                'status' => $status,
                'statusText' => (string)($current['description'] ?? TrackingStatus::label($status)),
                'estimatedDelivery' => self::parseDate((string)($detail['delivery']['estimated']['date'] ?? ''), 'America/New_York'),
                'deliveredAt' => $status === TrackingStatus::DELIVERED ? $delivered : null,
                'signedBy' => (string)($detail['delivery']['signedBy'] ?? $detail['signedBy'] ?? ''),
                'events' => $events,
            ]);
        }

        return $out;
    }
}
