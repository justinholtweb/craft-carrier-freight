<?php

namespace justinholtweb\carrierfreight\carriers;

use Craft;
use justinholtweb\carrier\base\Carrier;
use justinholtweb\carrier\base\Field;
use justinholtweb\carrier\base\Response;
use justinholtweb\carrier\base\ShipmentResult;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\helpers\Units;
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\models\canonical\Label;
use justinholtweb\carrier\models\canonical\Parcel;
use justinholtweb\carrier\models\canonical\Rate;
use justinholtweb\carrier\models\canonical\ShipmentRequest;

/**
 * What the six LTL carriers have in common, written once.
 *
 * LTL is not parcel shipping with heavier boxes. A shipment is a set of *handling units* (pallets,
 * crates, drums), each with a freight class and maybe an NMFC item; extras such as a liftgate are
 * priced line items rather than flags; the document that matters is the bill of lading; and the
 * one tracking number is the PRO, which covers the whole shipment however many pallets are on it.
 *
 * So in this package:
 * - each canonical `Parcel` is one handling unit — weight in whole pounds rounded up, dimensions in
 *   whole inches rounded up, class kept as a string until the moment a carrier wants a number;
 * - each carrier maps `base\Accessorial` twice, once for its rating vocabulary and once for its
 *   BOL vocabulary, and declares only the accessorials it can map in *both* — a liftgate that is
 *   priced at checkout but silently missing from the BOL is the worst outcome on offer;
 * - creating a shipment means creating a BOL. The BOL PDF comes back as a `Label` of kind `bol`
 *   and any shipping labels as kind `label`; the PRO is the single tracking number.
 *
 * Residential is driven by the `residentialDelivery` / `residentialPickup` accessorials only, never
 * by `Address::$residential` — that flag defaults to true for every checkout address, and LTL
 * carriers charge real money for it.
 */
abstract class FreightCarrier extends Carrier
{
    /** The eighteen NMFC classes, as the canonical strings every carrier is fed from. */
    public const CLASSES = ['50', '55', '60', '65', '70', '77.5', '85', '92.5', '100', '110', '125', '150', '175', '200', '250', '300', '400', '500'];

    /** Canonical packaging words this package understands. Anything else is treated as a pallet. */
    public const PACKAGING_WORDS = ['pallet', 'skid', 'crate', 'carton', 'box', 'drum', 'bag', 'tote', 'pail', 'roll', 'reel', 'bundle', 'loose'];

    /**
     * `Accessorial::* => carrier rating code`. An empty string means "mapped, but expressed as a
     * flag rather than a code" (TForce residential, hazmat everywhere).
     */
    protected const RATE_ACCESSORIALS = [];

    /** `Accessorial::* => carrier BOL code`, with the same empty-string convention. */
    protected const BOL_ACCESSORIALS = [];

    /**
     * Whether `createShipment()` should ask for a fresh quote and reference its number on the
     * BOL, for carriers whose quoted price is honoured only when the BOL cites it.
     */
    protected const REQUOTE_FOR_BOL = false;

    public static function region(): string
    {
        return Craft::t('carrier', 'Freight (US)');
    }

    /**
     * The accessorials this carrier can both price and put on a BOL.
     *
     * @return string[]
     */
    protected static function supportedAccessorials(): array
    {
        return array_keys(array_intersect_key(static::RATE_ACCESSORIALS, static::BOL_ACCESSORIALS));
    }

    /**
     * Settings every freight connection shares, appended after each carrier's credentials.
     */
    protected static function freightFields(): array
    {
        $classes = ['' => 'None — every handling unit must carry its own class'];

        foreach (self::CLASSES as $class) {
            $classes[$class] = 'Class ' . $class;
        }

        return [
            Field::heading('Freight defaults'),
            Field::select('defaultFreightClass', 'Default freight class', $classes, [
                'default' => '',
                'instructions' => 'Used for any handling unit that arrives without a freight class. Leave as None to refuse such shipments rather than guess.',
            ]),
            Field::text('commodityDescription', 'Commodity description', [
                'default' => 'General merchandise',
                'instructions' => 'Printed on the bill of lading when a handling unit has no description of its own.',
            ]),
        ];
    }

    // =========================================================================
    // Handling units
    // =========================================================================

    /**
     * Turn the request's parcels into handling units, or explain which one cannot be shipped.
     *
     * @return array<int, array{weightLb:int, lengthIn:int, widthIn:int, heightIn:int, hasDims:bool,
     *     pieces:int, class:string, nmfcItem:string, nmfcSub:string, hazmat:bool, packaging:string,
     *     description:string}>
     */
    protected function handlingUnits(ShipmentRequest $request, ?string &$error = null): array
    {
        $error = null;
        $units = [];

        if ($request->parcels === []) {
            $error = Craft::t('carrier', 'A freight shipment needs at least one handling unit.');

            return [];
        }

        $default = self::normaliseClass((string)$this->setting('defaultFreightClass', ''));
        $hazmatAll = in_array(\justinholtweb\carrier\base\Accessorial::HAZMAT, $request->accessorials, true);

        foreach (array_values($request->parcels) as $i => $parcel) {
            /** @var Parcel $parcel */
            $class = self::normaliseClass($parcel->freightClass);

            if ($class === '' && $parcel->freightClass !== '') {
                $error = Craft::t('carrier', 'Handling unit {n} has freight class “{class}”, which is not an NMFC class.', ['n' => $i + 1, 'class' => $parcel->freightClass]);

                return [];
            }

            if ($class === '') {
                $class = $default;
            }

            if ($class === '') {
                $error = Craft::t('carrier', 'Handling unit {n} has no freight class. Set one on the box, or a default class on the connection.', ['n' => $i + 1]);

                return [];
            }

            [$item, $sub] = self::splitNmfc($parcel->nmfc);
            $hasDims = $parcel->hasDimensions();

            $units[] = [
                'weightLb' => Units::gramsToWholeLb(max(1, $parcel->weightG)),
                'lengthIn' => $hasDims ? (int)Units::mmToIn($parcel->lengthMm) : 0,
                'widthIn' => $hasDims ? (int)Units::mmToIn($parcel->widthMm) : 0,
                'heightIn' => $hasDims ? (int)Units::mmToIn($parcel->heightMm) : 0,
                'hasDims' => $hasDims,
                'pieces' => max(1, $parcel->pieces),
                'class' => $class,
                'nmfcItem' => $item,
                'nmfcSub' => $sub,
                'hazmat' => $parcel->hazmat || $hazmatAll,
                'packaging' => self::normalisePackaging($parcel->packagingType),
                'description' => $parcel->description !== '' ? $parcel->description : $this->commodityDescription(),
            ];
        }

        return $units;
    }

    protected function commodityDescription(): string
    {
        $value = trim((string)$this->setting('commodityDescription', ''));

        return $value !== '' ? $value : 'General merchandise';
    }

    protected static function totalWeightLb(array $units): int
    {
        return array_sum(array_column($units, 'weightLb'));
    }

    protected static function anyHazmat(array $units): bool
    {
        return in_array(true, array_column($units, 'hazmat'), true);
    }

    /**
     * "77.50", "77,5" and 77.5 are all class 77.5; "77" is not a class. Returns '' for anything
     * that is not one of the eighteen.
     */
    public static function normaliseClass(string $value): string
    {
        $value = str_replace(',', '.', trim($value));

        if ($value === '' || !is_numeric($value)) {
            return '';
        }

        // Compare as tenths in integers: 92.5 and "92.50" must agree, and floats must not decide.
        $tenths = (int)round(((float)$value) * 10);

        foreach (self::CLASSES as $class) {
            if ((int)round(((float)$class) * 10) === $tenths) {
                return $class;
            }
        }

        return '';
    }

    /** A class as a JSON number, for the APIs that want one: 70 stays an int, 77.5 a float. */
    protected static function classNumber(string $class): int|float
    {
        return str_contains($class, '.') ? (float)$class : (int)$class;
    }

    /**
     * "123456-03", "123456 03", "12345603" → ['123456', '03']. Subs keep their leading zero — an
     * integer NMFC sub is how "03" becomes 3 and a reclass invoice.
     *
     * @return array{0:string, 1:string}
     */
    public static function splitNmfc(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return ['', ''];
        }

        if (preg_match('/^(\d{1,6})\s*[-\s.\/]\s*(\d{1,2})$/', $value, $m)) {
            return [$m[1], str_pad($m[2], 2, '0', STR_PAD_LEFT)];
        }

        $digits = preg_replace('/\D/', '', $value);

        if (strlen((string)$digits) === 8) {
            return [substr($digits, 0, 6), substr($digits, 6)];
        }

        return [(string)$digits, ''];
    }

    public static function normalisePackaging(string $value): string
    {
        $value = strtolower(trim($value));

        return match (true) {
            $value === '' => 'pallet',
            in_array($value, ['pallet', 'pallets', 'plt', 'pat', 'skid pallet'], true) => 'pallet',
            in_array($value, ['skid', 'skids', 'skd'], true) => 'skid',
            in_array($value, ['crate', 'crates', 'crt'], true) => 'crate',
            in_array($value, ['carton', 'cartons', 'ctn'], true) => 'carton',
            in_array($value, ['box', 'boxes', 'bx'], true) => 'box',
            in_array($value, ['drum', 'drums', 'drm', 'barrel'], true) => 'drum',
            in_array($value, ['bag', 'bags', 'sack'], true) => 'bag',
            in_array($value, ['tote', 'totes'], true) => 'tote',
            in_array($value, ['pail', 'pails', 'bucket'], true) => 'pail',
            in_array($value, ['roll', 'rolls'], true) => 'roll',
            in_array($value, ['reel', 'reels'], true) => 'reel',
            in_array($value, ['bundle', 'bundles', 'bdl'], true) => 'bundle',
            in_array($value, ['loose', 'lse', 'pieces', 'pcs'], true) => 'loose',
            default => 'pallet',
        };
    }

    /** A carrier's packaging code for a canonical word, falling back to its pallet code. */
    protected static function packagingCode(string $packaging, array $map): string
    {
        return $map[$packaging] ?? $map['pallet'] ?? 'PLT';
    }

    // =========================================================================
    // Accessorials
    // =========================================================================

    /**
     * The carrier codes for the requested accessorials out of one of the maps. Flag-only entries
     * (an empty code) are left for the caller to express its own way.
     *
     * @return string[]
     */
    protected static function codesFor(ShipmentRequest $request, array $map): array
    {
        $codes = [];

        foreach ($request->accessorials as $accessorial) {
            $code = $map[$accessorial] ?? '';

            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    protected static function wants(ShipmentRequest $request, string $accessorial): bool
    {
        return in_array($accessorial, $request->accessorials, true);
    }

    // =========================================================================
    // Quotes
    // =========================================================================

    /**
     * The quote number to cite on the BOL, and the rate it came with.
     *
     * Carrier's engine does not (yet) carry the quote id from checkout to label time, so a caller
     * can pass one through `ShipmentRequest::$options['quoteId']`. Without one, carriers that
     * honour a quoted price only when the BOL cites it (`REQUOTE_FOR_BOL`) quote again here, for
     * the one service being shipped. A failed re-quote is a warning, never a reason to refuse the
     * BOL: the carrier then bills its tariff, which is what it would have done anyway.
     */
    protected function quoteForBol(ShipmentRequest $request, array &$warnings): ?Rate
    {
        $given = trim((string)$request->option('quoteId', ''));

        if ($given !== '') {
            return new Rate(['serviceCode' => $request->serviceCode, 'quoteId' => $given]);
        }

        if (!static::REQUOTE_FOR_BOL) {
            return null;
        }

        $result = $this->rate(clone $request);
        $rate = $request->serviceCode !== '' ? $result->forService($request->serviceCode) : $result->cheapest();

        if ($rate === null || $rate->quoteId === '') {
            $warnings[] = Craft::t('carrier', 'No quote number could be obtained for the bill of lading, so {carrier} will bill its tariff rate. {reason}', [
                'carrier' => static::displayName(),
                'reason' => (string)($result->error ?? ''),
            ]);

            return $rate;
        }

        return $rate;
    }

    // =========================================================================
    // Documents and results
    // =========================================================================

    /**
     * Raw document bytes out of whatever the carrier sent: base64 (the usual), or already-raw
     * bytes (a couple of these APIs are documented as returning "binary" inside JSON).
     */
    protected static function decodeDocument(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        if (str_starts_with($value, '%PDF') || str_contains(substr($value, 0, 200), '^XA')) {
            return $value;
        }

        $decoded = base64_decode(preg_replace('/\s+/', '', $value), true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * A `Label` for some document bytes, or null when the bytes are not what the format claims —
     * an HTML error page stored as a "PDF" is a support ticket waiting to happen.
     */
    protected static function document(mixed $encoded, string $format, string $kind, string $trackingNumber): ?Label
    {
        $bytes = self::decodeDocument($encoded);

        if (!LabelFormat::bytesMatch($format, $bytes)) {
            return null;
        }

        return new Label([
            'parcelIndex' => null,
            'trackingNumber' => $trackingNumber,
            'format' => $format,
            'kind' => $kind,
            'data' => $bytes,
        ]);
    }

    /**
     * The result for a BOL that exists. Without a PRO there is nothing to track, and without
     * documents there is nothing to hand the driver; either way the BOL was created, so a retry
     * would create a second one. Both cases come back *uncertain* — parked for a human, with the
     * carrier's ids kept so that human can find it — never as a clean refusal and never as success.
     *
     * @param Label[] $documents shipping labels first, then the BOL, so the shipment's label
     *        format is the one the merchant prints on
     */
    protected function bolResult(string $pro, string $shipmentId, array $documents, array $data, array $warnings = []): ShipmentResult
    {
        $documents = array_values(array_filter($documents));

        if ($pro === '') {
            $result = ShipmentResult::uncertain(Craft::t('carrier', '{carrier} accepted the bill of lading{id} but returned no PRO number. Look it up in the carrier’s portal before trying again.', [
                'carrier' => static::displayName(),
                'id' => $shipmentId !== '' ? ' ' . $shipmentId : '',
            ]));
            $result->shipmentId = $shipmentId;
            $result->data = $data;
            $result->warnings = $warnings;

            return $result;
        }

        if ($documents === []) {
            $result = ShipmentResult::uncertain(Craft::t('carrier', '{carrier} created PRO {pro} but sent no readable bill of lading. Download it from the carrier’s portal; do not create the shipment again.', [
                'carrier' => static::displayName(),
                'pro' => $pro,
            ]));
            $result->shipmentId = $shipmentId !== '' ? $shipmentId : $pro;
            $result->trackingNumbers = [$pro];
            $result->data = $data;
            $result->warnings = $warnings;

            return $result;
        }

        $result = ShipmentResult::ok($shipmentId !== '' ? $shipmentId : $pro, [$pro], $documents, $data);
        $result->warnings = $warnings;

        return $result;
    }

    /**
     * Create a BOL through the NMFTA eBOL shape — the whole of `createShipment()` for ODFL, Estes,
     * SAIA and R+L, which differ only in their profile, URL and headers.
     *
     * @param callable|null $fetchMissing `fn(string $pro, string $format): array{bol: ?Label,
     *        labels: Label[]}` for a carrier whose create answer may omit the documents (R+L)
     */
    protected function createEbolShipment(
        ShipmentRequest $request,
        \justinholtweb\carrierfreight\ebol\NmftaEbol $profile,
        string $uri,
        array $httpOptions = [],
        ?callable $fetchMissing = null,
    ): ShipmentResult {
        $units = $this->handlingUnits($request, $error);

        if ($error !== null) {
            return ShipmentResult::rejected($error);
        }

        $warnings = [];
        $quote = $this->quoteForBol($request, $warnings);
        $format = $this->labelFormatFor($request);

        $body = $profile->build(
            $request,
            $units,
            // `ebolExtraCodes`: codes a carrier adds itself, e.g. ODFL's guaranteed-service GTD_PM.
            array_values(array_unique(array_merge(
                self::codesFor($request, static::BOL_ACCESSORIALS),
                array_map('strval', (array)$request->option('ebolExtraCodes', [])),
                // A hazmat handling unit makes the shipment hazmat whether or not anyone ticked it.
                self::anyHazmat($units) && (static::BOL_ACCESSORIALS[\justinholtweb\carrier\base\Accessorial::HAZMAT] ?? '') !== ''
                    ? [static::BOL_ACCESSORIALS[\justinholtweb\carrier\base\Accessorial::HAZMAT]]
                    : [],
            ))),
            $quote?->quoteId ?? '',
            $format,
            false,
            self::shipDate($request),
            $this->commodityDescription(),
        );

        $response = $this->transport()->request('POST', $uri, array_merge($httpOptions, ['json' => $body]));
        $parsed = \justinholtweb\carrierfreight\ebol\NmftaEbol::parse($response);

        if (!$response->ok()) {
            return $this->failure($response, $parsed['message'] !== '' ? $parsed['message'] : null);
        }

        if ($parsed['status'] === 'FAIL') {
            return ShipmentResult::rejected($this->redactSecrets($parsed['message'] !== ''
                ? $parsed['message']
                : Craft::t('carrier', '{carrier} refused the bill of lading without saying why.', ['carrier' => static::displayName()])));
        }

        $pro = $parsed['pro'];
        $labels = [];

        foreach ($parsed['labels'] as $encoded) {
            $labels[] = self::document($encoded, $format, 'label', $pro);
        }

        $bol = self::document($parsed['bol'], LabelFormat::PDF_LETTER, 'bol', $pro);

        if ($pro !== '' && $bol === null && $fetchMissing !== null) {
            $fetched = $fetchMissing($pro, $format);
            $bol = $fetched['bol'] ?? null;

            if (array_filter($labels) === []) {
                $labels = $fetched['labels'] ?? [];
            }
        }

        if ($parsed['status'] === 'WARNING' && $parsed['message'] !== '') {
            $warnings[] = $parsed['message'];
        }

        $result = $this->bolResult($pro, '', array_merge($labels, [$bol]), [
            'pro' => $pro,
            'quoteId' => $quote?->quoteId ?: $parsed['quoteId'],
            'bolNumber' => $request->reference,
        ], array_merge($warnings, $parsed['warnings']));

        if ($result->success && $quote !== null && $quote->amount > 0) {
            $result->withCharge($quote->amount, $quote->currency ?: 'USD');
        }

        return $result;
    }

    /** A failed answer, classified, with any carrier-specific sentence preferred and no secrets. */
    protected function failure(Response $response, ?string $message = null): ShipmentResult
    {
        $message = $message !== null && trim($message) !== '' ? $message : $response->errorMessage();

        return ShipmentResult::fromResponse($response, $this->redactSecrets($message));
    }

    /** A rate error that never carries a credential. */
    protected function rateError(Response $response, ?string $message = null): string
    {
        $message = $message !== null && trim($message) !== '' ? $message : $response->errorMessage();

        return $this->redactSecrets($message);
    }

    // =========================================================================
    // Small translations
    // =========================================================================

    /**
     * Money as these carriers send it: numbers, strings, strings with "$" and thousands commas.
     * Rounded to cents through string arithmetic rather than trusting a float sum.
     */
    protected static function money(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float)$value, 2);
        }

        if (!is_string($value)) {
            return null;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', $value);

        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }

        return round((float)$clean, 2);
    }

    /** ODFL and R+L want ISO 3166 alpha-3. */
    protected static function iso3(string $country): string
    {
        return match (strtoupper($country)) {
            'US' => 'USA',
            'CA' => 'CAN',
            'MX' => 'MEX',
            'PR' => 'PRI',
            default => strtoupper($country),
        };
    }

    /** Ten US digits from a normalised "+1704…" number, as every LTL portal wants it. */
    protected static function usPhone(string $phone): string
    {
        $digits = (string)preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    /** First five digits of a US ZIP+4; Canadian postcodes without the space. */
    protected static function postcode(Address $address): string
    {
        $postcode = strtoupper(trim($address->postcode));

        if (strtoupper($address->countryCode) === 'US' && preg_match('/^(\d{5})/', $postcode, $m)) {
            return $m[1];
        }

        return str_replace(' ', '', $postcode);
    }

    protected static function shipDate(ShipmentRequest $request): \DateTime
    {
        return $request->shipDate ? clone $request->shipDate : new \DateTime('today');
    }

    /**
     * The rule of thumb from the research, for carriers that only send words: delivered first,
     * then out for delivery, then trouble, then "nothing has happened yet", else moving.
     */
    protected static function statusFromText(string $text, string $default = TrackingStatus::IN_TRANSIT): string
    {
        $text = strtolower($text);

        if ($text === '') {
            return $default;
        }

        return match (true) {
            str_contains($text, 'cancel') || str_contains($text, 'void') => TrackingStatus::CANCELLED,
            str_contains($text, 'out for delivery') => TrackingStatus::OUT_FOR_DELIVERY,
            (bool)preg_match('/\b(refus|damage|undeliver|unable to deliver|short|exception|delay|attempt|reattempt|return)/', $text) => TrackingStatus::EXCEPTION,
            str_contains($text, 'delivered') => TrackingStatus::DELIVERED,
            (bool)preg_match('/pickup (requested|scheduled|pending|rescheduled)|not yet picked|awaiting pickup|bill of lading|bol (created|received)/', $text) => TrackingStatus::PRE_TRANSIT,
            default => $default,
        };
    }

    /** "City, ST" without stray commas when one half is missing. */
    protected static function place(?string ...$parts): string
    {
        return implode(', ', array_filter(array_map(static fn($p) => trim((string)$p), $parts), static fn($p) => $p !== ''));
    }
}
