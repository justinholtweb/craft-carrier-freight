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
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\models\canonical\Rate;
use justinholtweb\carrier\models\canonical\ShipmentRef;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\canonical\TrackingEvent;
use justinholtweb\carrier\models\canonical\TrackingInfo;
use justinholtweb\carrier\models\Connection;
use justinholtweb\carrierfreight\auth\SessionTokenAuth;

/**
 * XPO (LTL).
 *
 * Rating `POST /rating/1.0/ratequotes`, BOL `POST /billoflading/1.0/billsoflading`, BOL PDF
 * `GET …/{bolInstId}/pdf`, shipping labels `POST …/{bolInstId}/shippinglabels/pdf`, void
 * `PUT …/{bolInstId}/cancel`, tracking `GET /tracking/1.0/shipments/shipment-status-details`.
 * Sources: XPO's API implementation guides (Rating, BillOfLading, Shipment Tracking), via
 * docs/research/ltl-freight.md §4, plus a direct reading of the BillOfLading guide for the
 * create/get/pdf/label/cancel samples (party, `commodityLine`, `additionalService`, `autoAssignPro`).
 *
 * Authentication: `POST /token` with Basic base64(consumerKey:consumerSecret) and a **password**
 * grant for the XPO LTL web user. Access tokens last 12 h.
 *
 * Vendor traps:
 * - **There is no sandbox.** BOL calls take a mandatory `testMode=Y|N`; rating and tracking always
 *   hit production. The connection's "Test mode" setting sets `testMode=Y`. A test-mode BOL
 *   cannot be cancelled.
 * - **No PRO comes back from create.** The response is `data.bolInfo.bolInstId`. With
 *   `autoAssignPro: true`, XPO "will generate one and assign after the BoL is submitted", so this
 *   reads it back with `GET …/{bolInstId}/requester` (`data.billOfLading.proNbr`). If it is still
 *   empty the result is **uncertain**, not success: the BOL exists (a retry would create a second),
 *   but there is no number anyone can track, and the BOL instance id is not a documented tracking
 *   reference. The merchant finds it in XPO's portal by the instance id kept in the message and
 *   `data`. Merchants with pre-assigned PROs pass `options['proNumber']`, which is sent as
 *   `bol.proNbr` with `autoAssignPro: false` and avoids the problem.
 * - `totCharge` is an **array** of `{amt}`.
 * - `nmfcClass` is a number in the rating request and a string ("100.0") in the response; the BOL
 *   sample sends it as a string ("100").
 * - **Canada is `CN`, not `CA`, in the BOL** (changed 2021-10-12).
 * - Dimension unit "INCH", weight unit "LBS"; response dates are epoch milliseconds (UTC).
 * - Appointment is `TDC` in rating and `APT` on the BOL; guaranteed is accessorial `GUR` (G!).
 * - Every response is wrapped in `{code, transactionTimestamp, data, error}` and `code` is a string.
 * - COD has been disabled since 2025-05-29.
 * - Rate limits are per token over a sliding minute, Bronze (5/min) to Diamond (100/min). The
 *   transport honours 429 + Retry-After rather than throttling everyone to Bronze.
 *
 * Quote numbers: the research found no BOL field citing `confirmationNbr`, so nothing is re-quoted.
 *
 * **UNVERIFIED**:
 * - Limited access in *rating*: the BOL accepts `DLA`/`OLA` but the 2021 rating list does not show
 *   them, so limited access is not offered.
 * - `contentType` in the PDF responses is documented as "(binary file of the PDF)"; both base64 and
 *   raw bytes are accepted.
 * - `commodityLine.sub` (NMFC sub, added 2026-08) is not in the guide version read; NMFC subs are
 *   not sent.
 * - The `error` object's field names; the refresh-token grant parameters (unused — a 401 mints a
 *   new token instead).
 * - The public tracking page URL.
 */
class XpoCarrier extends FreightCarrier
{
    private const HOST = 'https://api.ltl.xpo.com';

    protected const RATE_ACCESSORIALS = [
        Accessorial::LIFTGATE_PICKUP => 'OLG',
        Accessorial::LIFTGATE_DELIVERY => 'DLG',
        Accessorial::RESIDENTIAL_PICKUP => 'RSO',
        Accessorial::RESIDENTIAL_DELIVERY => 'RSD',
        Accessorial::INSIDE_DELIVERY => 'DID',
        Accessorial::NOTIFY => 'DNC',
        Accessorial::APPOINTMENT => 'TDC',
        Accessorial::HAZMAT => '',
    ];

    protected const BOL_ACCESSORIALS = [
        Accessorial::LIFTGATE_PICKUP => 'OLG',
        Accessorial::LIFTGATE_DELIVERY => 'DLG',
        Accessorial::RESIDENTIAL_PICKUP => 'RSO',
        Accessorial::RESIDENTIAL_DELIVERY => 'RSD',
        Accessorial::INSIDE_DELIVERY => 'DID',
        Accessorial::LIMITED_ACCESS_PICKUP => 'OLA',
        Accessorial::LIMITED_ACCESS_DELIVERY => 'DLA',
        Accessorial::NOTIFY => 'DNC',
        Accessorial::APPOINTMENT => 'APT',
        Accessorial::HAZMAT => '',
    ];

    private const PACKAGING = [
        'pallet' => 'PLT',
        'skid' => 'SKD',
        'crate' => 'CRT',
        'carton' => 'CTN',
        'box' => 'BOX',
        'drum' => 'DRM',
        'bag' => 'BAG',
        'tote' => 'TOTE',
        'pail' => 'PAIL',
        'roll' => 'ROLL',
        'reel' => 'REEL',
        'bundle' => 'BDL',
        'loose' => 'LSE',
    ];

    private const STATUS = [
        '32' => TrackingStatus::PRE_TRANSIT,
        '31' => TrackingStatus::PRE_TRANSIT,
        '8' => TrackingStatus::OUT_FOR_DELIVERY,
        '23' => TrackingStatus::DELIVERED,
        '28' => TrackingStatus::DELIVERED,
        '30' => TrackingStatus::DELIVERED,
        '26' => TrackingStatus::CANCELLED,
        '4' => TrackingStatus::EXCEPTION,
        '7' => TrackingStatus::EXCEPTION,
        '12' => TrackingStatus::EXCEPTION,
        '15' => TrackingStatus::EXCEPTION,
        '17' => TrackingStatus::EXCEPTION,
        '19' => TrackingStatus::EXCEPTION,
        '20' => TrackingStatus::EXCEPTION,
        '21' => TrackingStatus::EXCEPTION,
        '22' => TrackingStatus::EXCEPTION,
        '27' => TrackingStatus::EXCEPTION,
        '33' => TrackingStatus::EXCEPTION,
        '35' => TrackingStatus::EXCEPTION,
        '36' => TrackingStatus::EXCEPTION,
        '37' => TrackingStatus::EXCEPTION,
    ];

    public static function handle(): string
    {
        return 'freight-xpo';
    }

    public static function displayName(): string
    {
        return 'XPO';
    }

    public static function description(): string
    {
        return Craft::t('carrier', 'LTL quotes, bills of lading with shipping labels, voids and PRO tracking from XPO.');
    }

    public static function setupUrl(): ?string
    {
        return 'https://www.xpo.com/help-center/api/';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Feature::FREIGHT, Feature::RATES, Feature::LABELS, Feature::CANCEL, Feature::TRACKING)
            ->labelFormats(LabelFormat::PDF_LETTER)
            ->originCountries('US', 'CA')
            ->accessorials(...static::supportedAccessorials());
    }

    public static function settingsFields(): array
    {
        return array_merge([
            Field::secret('consumerKey', 'Consumer key', [
                'required' => true,
                'instructions' => 'Email LTLWebAPISupport@xpo.com from your registered XPO web user to subscribe.',
            ]),
            Field::secret('consumerSecret', 'Consumer secret', ['required' => true]),
            Field::text('username', 'XPO LTL web username', ['required' => true]),
            Field::secret('password', 'XPO LTL web password', ['required' => true]),
            Field::text('accountNumber', 'XPO account (acctInstId)', [
                'instructions' => 'Your shipper account instance id, for contract pricing.',
            ]),
            Field::boolean('testMode', 'Test mode', [
                'instructions' => 'XPO has no sandbox. Test mode sends testMode=Y on bills of lading; rating and tracking always use production.',
            ]),
        ], static::freightFields());
    }

    public static function services(): array
    {
        return [
            'standard' => ['name' => 'XPO LTL', 'international' => false, 'points' => false],
            'guaranteed' => ['name' => 'XPO Guaranteed', 'international' => false, 'points' => false],
        ];
    }

    /** UNVERIFIED: XPO's public tracking page. */
    public static function trackingUrl(string $trackingNumber): ?string
    {
        return 'https://www.xpo.com/track/?pro=' . rawurlencode($trackingNumber);
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new SessionTokenAuth(
            static fn(Connection $c) => ['POST', self::HOST . '/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode(SessionTokenAuth::settingOf($c, 'consumerKey') . ':' . SessionTokenAuth::settingOf($c, 'consumerSecret')),
                    'Accept' => 'application/json',
                ],
                'form' => [
                    'grant_type' => 'password',
                    'username' => SessionTokenAuth::settingOf($c, 'username'),
                    'password' => SessionTokenAuth::settingOf($c, 'password'),
                ],
            ]],
            ['consumerKey', 'consumerSecret', 'username', 'password'],
            ['access_token'],
            43200,
        );
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri(self::HOST)
            ->setDefaultHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json']);
    }

    private function testMode(): array
    {
        return ['testMode' => $this->boolSetting('testMode') ? 'Y' : 'N'];
    }

    protected function probe(): HealthResult
    {
        $auth = $this->auth();

        if (!$auth instanceof SessionTokenAuth || $auth->accessToken() === null) {
            return HealthResult::fail(
                Craft::t('carrier', 'XPO did not issue a token for these credentials.'),
                [Craft::t('carrier', 'The consumer key and secret come from LTLWebAPISupport@xpo.com; the username and password are your XPO LTL web login.')],
            );
        }

        $response = $this->transport()->get('rating/1.0/health-check');

        if ($response->unauthorized()) {
            return HealthResult::fail($this->redactSecrets($response->errorMessage()), [
                Craft::t('carrier', 'Your web user is not subscribed to the Rating API. Ask LTLWebAPISupport@xpo.com.'),
            ]);
        }

        return HealthResult::pass(Craft::t('carrier', 'Signed in to XPO as {user}.', ['user' => (string)$this->setting('username')]), [
            'Test mode' => $this->boolSetting('testMode') ? 'On (bills of lading are not real)' : 'Off',
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

        $commodities = [];
        $pallets = 0;

        foreach ($units as $unit) {
            $commodity = [
                'grossWeight' => ['weight' => $unit['weightLb'], 'weightUom' => 'LBS'],
                'nmfcClass' => self::classNumber($unit['class']),
                'hazmatInd' => $unit['hazmat'],
                'pieceCnt' => $unit['pieces'],
            ];

            // XPO: any dimension means all three, in inches.
            if ($unit['hasDims']) {
                $commodity['dimensions'] = ['length' => $unit['lengthIn'], 'width' => $unit['widthIn'], 'height' => $unit['heightIn'], 'dimensionsUom' => 'INCH'];
            }

            $pallets += in_array($unit['packaging'], ['pallet', 'skid'], true) ? 1 : 0;
            $commodities[] = $commodity;
        }

        $date = self::shipDate($request)->setTime(12, 0);
        $shipper = ['address' => ['postalCd' => self::postcode($request->shipFrom)]];
        $account = trim((string)$this->setting('accountNumber', ''));

        if ($account !== '') {
            $shipper = ['acctInstId' => $account] + $shipper;
        }

        $base = [
            'paymentTermCd' => 'P',
            'shipmentDate' => $date->format('Y-m-d\TH:i:s.vO'),
            'shipper' => $shipper,
            'consignee' => ['address' => ['postalCd' => self::postcode($request->shipTo)]],
            'commodity' => $commodities,
            'palletCnt' => $pallets,
        ];

        $codes = $request->serviceCode !== '' ? [$request->serviceCode] : array_keys(self::services());
        $rates = [];
        $warnings = [];
        $errors = [];

        foreach ($codes as $code) {
            if (!isset(self::services()[$code])) {
                $errors[] = Craft::t('carrier', 'Unknown XPO service “{code}”.', ['code' => $code]);
                continue;
            }

            $accessorials = self::codesFor($request, static::RATE_ACCESSORIALS);

            if ($code === 'guaranteed') {
                $accessorials[] = 'GUR';
            }

            $shipmentInfo = $base;

            if ($accessorials !== []) {
                $shipmentInfo['accessorials'] = array_map(static fn($c) => ['accessorialCd' => $c], $accessorials);
            }

            $response = $this->transport()->post('rating/1.0/ratequotes', ['shipmentInfo' => $shipmentInfo]);

            if (!$response->ok() || is_array($response->at('error')) && $response->at('error') !== []) {
                $errors[] = $this->rateError($response);
                continue;
            }

            foreach ((array)$response->at('data.msgs', []) as $msg) {
                if (is_array($msg) && !empty($msg['message'])) {
                    $warnings[] = (string)$msg['message'];
                }
            }

            $amount = self::money($response->at('data.rateQuote.totCharge.0.amt'));

            if ($amount === null || $amount <= 0) {
                continue;
            }

            $days = $response->at('data.transitTime.transitDays') ?? $response->at('data.rateQuote.transitTime.transitDays');
            $eta = $response->at('data.transitTime.estdDlvrDate');

            $rates[] = new Rate([
                'serviceCode' => $code,
                'serviceName' => self::services()[$code]['name'],
                'amount' => $amount,
                'currency' => strtoupper((string)($response->at('data.rateQuote.totCharge.0.currencyCd') ?? 'USD')) ?: 'USD',
                'transitDays' => is_numeric($days) ? (int)$days : null,
                'estimatedDelivery' => is_numeric($eta) ? gmdate('Y-m-d', intdiv((int)$eta, 1000)) : null,
                'quoteId' => (string)$response->at('data.rateQuote.confirmationNbr', ''),
            ]);
        }

        if ($rates === [] && $errors !== []) {
            return RateResult::failed($errors[0]);
        }

        return RateResult::ok($rates, $warnings);
    }

    // =========================================================================
    // Bill of lading, void
    // =========================================================================

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        $units = $this->handlingUnits($request, $error);

        if ($error !== null) {
            return ShipmentResult::rejected($error);
        }

        if (!isset(self::services()[$request->serviceCode])) {
            return ShipmentResult::rejected(Craft::t('carrier', 'Unknown XPO service “{code}”.', ['code' => $request->serviceCode]));
        }

        $lines = [];

        foreach ($units as $unit) {
            $lines[] = array_filter([
                'pieceCnt' => $unit['pieces'],
                'packaging' => ['packageCd' => self::packagingCode($unit['packaging'], self::PACKAGING)],
                'grossWeight' => ['weight' => $unit['weightLb']],
                'desc' => mb_substr($unit['description'], 0, 100),
                'nmfcClass' => $unit['class'],
                'nmfcItemCd' => $unit['nmfcItem'],
                'hazmatInd' => $unit['hazmat'],
            ], static fn($v) => $v !== '' && $v !== null);
        }

        $codes = self::codesFor($request, static::BOL_ACCESSORIALS);

        if ($request->serviceCode === 'guaranteed') {
            $codes[] = 'GUR';
        }

        $bol = [
            'requester' => ['role' => 'S'],
            'consignee' => $this->party($request->shipTo),
            'shipper' => $this->party($request->shipFrom),
            'billToCust' => $this->party($request->shipFrom),
            'commodityLine' => $lines,
            'chargeToCd' => 'P',
        ];

        if ($codes !== []) {
            $bol['additionalService'] = array_map(static fn($c) => ['accsrlCode' => $c, 'prepaidOrCollect' => 'P'], $codes);
        }

        if (self::anyHazmat($units)) {
            $bol['emergencyContactName'] = mb_substr($request->shipFrom->contactName(), 0, 40);
            $bol['emergencyContactPhone'] = ['phoneNbr' => self::usPhone($request->shipFrom->phone)];
        }

        if ($request->instructions !== '') {
            $bol['remarks'] = mb_substr($request->instructions, 0, 250);
        }

        if ($request->reference !== '') {
            $bol['suppRef'] = ['otherRefs' => [[
                'referenceCode' => 'OOO',
                'reference' => mb_substr($request->reference, 0, 30),
                'referenceDescr' => 'Order',
                'referenceTypeCd' => 'Other',
            ]]];
        }

        $suppliedPro = trim((string)$request->option('proNumber', ''));

        if ($suppliedPro !== '') {
            $bol['proNbr'] = $suppliedPro;
        }

        $response = $this->transport()->post('billoflading/1.0/billsoflading', [
            'bol' => $bol,
            'autoAssignPro' => $suppliedPro === '',
        ], ['query' => $this->testMode()]);

        if (!$response->ok()) {
            return $this->failure($response);
        }

        $bolInstId = (string)$response->at('data.bolInfo.bolInstId', '');

        if ($bolInstId === '') {
            // A 2xx with no BOL id: nothing says it was not created.
            return $this->bolResult('', '', [], []);
        }

        $pro = $suppliedPro;

        if ($pro === '') {
            $lookup = $this->transport()->get('billoflading/1.0/billsoflading/' . rawurlencode($bolInstId) . '/requester', $this->testMode());
            $pro = $lookup->ok() ? trim((string)$lookup->at('data.billOfLading.proNbr', '')) : '';
        }

        $data = [
            'bolInstId' => $bolInstId,
            'pro' => $pro,
            'pickupTerminal' => (string)$response->at('data.bolInfo.pkupTrmnlSic', ''),
            'testMode' => $this->boolSetting('testMode'),
        ];

        if ($pro === '') {
            return $this->bolResult('', $bolInstId, [], $data);
        }

        $documents = [];
        $labels = $this->transport()->post('billoflading/1.0/billsoflading/' . rawurlencode($bolInstId) . '/shippinglabels/pdf', [
            'nbrOfLabels' => max(1, count($units)),
            'labelPosition' => 1,
            'printerSettings' => 'Normal Printer Settings',
            'bolInstId' => $bolInstId,
        ], ['query' => $this->testMode()]);

        if ($labels->ok()) {
            $documents[] = self::document($labels->at('data.shippingLabel.contentType'), LabelFormat::PDF_LETTER, 'label', $pro);
        }

        $pdf = $this->transport()->get('billoflading/1.0/billsoflading/' . rawurlencode($bolInstId) . '/pdf', $this->testMode());

        if ($pdf->ok()) {
            $documents[] = self::document($pdf->at('data.bolpdf.contentType'), LabelFormat::PDF_LETTER, 'bol', $pro);
        }

        return $this->bolResult($pro, $bolInstId, $documents, $data);
    }

    /**
     * BOL party: `address` plus `contactInfo` exactly as the guide's create sample. Canada is CN.
     */
    private function party(?Address $address): array
    {
        if ($address === null) {
            return [];
        }

        $country = strtoupper($address->countryCode);

        return [
            'address' => array_filter([
                'careOfName' => mb_substr($address->contactName(), 0, 30),
                'addressLine1' => mb_substr($address->streetLine(), 0, 30),
                'addressLine2' => mb_substr($address->street2, 0, 30),
                'cityName' => $address->city,
                'stateCd' => $address->region,
                'countryCd' => $country === 'CA' ? 'CN' : $country,
                'postalCd' => self::postcode($address),
            ]),
            'contactInfo' => array_filter([
                'companyName' => mb_substr($address->company !== '' ? $address->company : $address->contactName(), 0, 30),
                'email' => $address->email !== '' ? ['emailAddr' => $address->email] : null,
                'phone' => self::usPhone($address->phone) !== '' ? ['phoneNbr' => self::usPhone($address->phone)] : null,
            ]),
        ];
    }

    public function cancelShipment(ShipmentRef $shipment): ActionResult
    {
        $id = (string)($shipment->data['bolInstId'] ?? $shipment->shipmentId);

        if ($id === '') {
            return ActionResult::failed(Craft::t('carrier', 'This shipment has no XPO bill of lading id to cancel.'));
        }

        $response = $this->transport()->put('billoflading/1.0/billsoflading/' . rawurlencode($id) . '/cancel', ['bolInstId' => $id], ['query' => $this->testMode()]);

        if (!$response->ok() || is_array($response->at('error')) && $response->at('error') !== []) {
            return ActionResult::failed($this->rateError($response));
        }

        return ActionResult::ok($id, Craft::t('carrier', 'XPO cancelled the bill of lading and any pickup booked with it.'));
    }

    // =========================================================================
    // Tracking
    // =========================================================================

    public function track(array $trackingNumbers): array
    {
        $out = [];

        foreach ($trackingNumbers as $number) {
            $response = $this->transport()->get('tracking/1.0/shipments/shipment-status-details', ['referenceNumbers' => (string)$number]);

            if (!$response->ok()) {
                if ($response->status === 404) {
                    $out[] = new TrackingInfo(['trackingNumber' => (string)$number, 'notFound' => true]);
                }

                continue;
            }

            $detail = $response->at('data.shipmentStatusDtls.0');

            if (!is_array($detail)) {
                $out[] = new TrackingInfo(['trackingNumber' => (string)$number, 'notFound' => true]);
                continue;
            }

            $current = (array)($detail['shipmentStatus'] ?? []);
            $code = (string)($current['statusCd'] ?? '');
            $status = $code !== '' ? self::mapStatus($code, self::STATUS, TrackingStatus::IN_TRANSIT) : TrackingStatus::PRE_TRANSIT;
            $when = self::epoch($current['dateTime'] ?? null);
            $description = trim((string)($current['description'] ?? '') . (!empty($current['reason']) ? ' — ' . $current['reason'] : ''));

            $out[] = new TrackingInfo([
                'trackingNumber' => (string)$number,
                'status' => $status,
                'statusText' => $description !== '' ? $description : TrackingStatus::label($status),
                'estimatedDelivery' => self::epoch($detail['estdDlvrDate'] ?? null),
                'deliveredAt' => $status === TrackingStatus::DELIVERED ? $when : null,
                // XPO reports the current status only, so it is the one event there is.
                'events' => $code === '' ? [] : [new TrackingEvent([
                    'occurredAt' => $when,
                    'status' => $status,
                    'code' => $code,
                    'description' => $description,
                    'location' => (string)($detail['currSic'] ?? ''),
                ])],
            ]);
        }

        return $out;
    }

    private static function epoch(mixed $milliseconds): ?\DateTime
    {
        if (!is_numeric($milliseconds) || (int)$milliseconds <= 0) {
            return null;
        }

        return (new \DateTime('@' . intdiv((int)$milliseconds, 1000)))->setTimezone(new \DateTimeZone('UTC'));
    }
}
