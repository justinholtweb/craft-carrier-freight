<?php

namespace justinholtweb\carrierfreight\ebol;

use justinholtweb\carrier\base\Accessorial;
use justinholtweb\carrier\base\Response;
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\models\canonical\ShipmentRequest;

/**
 * The NMFTA Digital LTL Council electronic bill of lading — one serializer for four carriers.
 *
 * ODFL (v2.1.0), Estes (v2.0.1), SAIA (v2.0.1) and R+L (`/BillOfLading/DigitalCouncilBOL`) all
 * accept the same JSON shape and answer with the same one: `referenceNumbers.pro`, base64
 * `images.bol` and `images.shippingLabels`, and a `messageStatus` that can say FAIL inside an
 * HTTP 200. Building it four times would mean four slightly different bugs, so each carrier hands
 * this class a small profile (version, country format, pallet code, quote field, label formats)
 * and this class does the rest.
 *
 * Source of the shape: the research summary of ODFL's eBOL guide v3.1 sample, Estes'
 * swagger.yaml (`/v1/bol`: `version` "v2.0"/"v2.0.1", `referenceNumbers.quoteID`, label formats
 * Avery/Avery2/Avery4/Letter/Zebra, `lineItemLayout` "Nested"), and SAIA's portal metadata.
 *
 * **UNVERIFIED** — the party objects. No primary source seen shows the exact nesting of
 * `origin` / `destination` / `billTo`. This sends `{address: {name, address1, address2, city,
 * stateProvince, postalCode, country}, contact: {name, phoneNumber, email}}`, which is what the
 * Estes swagger's property names point to. Confirm against each carrier's test environment
 * (`bol.isTest: true` validates without creating) before the first live BOL.
 *
 * **UNVERIFIED** — `lineItems[].hazardous` vs `isHazardous` and `packagingType` vs `packaging`:
 * ODFL's sample uses `hazardous` / `packagingType`, Estes' swagger shows `isHazardous` in rating.
 * This sends `hazardous` and `packagingType`, as ODFL's sample does.
 */
final class NmftaEbol
{
    /** The shared NMFTA accessorial vocabulary. */
    public const ACCESSORIALS = [
        Accessorial::LIFTGATE_PICKUP => 'LFTP',
        Accessorial::LIFTGATE_DELIVERY => 'LFTD',
        Accessorial::RESIDENTIAL_PICKUP => 'REP',
        Accessorial::RESIDENTIAL_DELIVERY => 'RES',
        Accessorial::INSIDE_DELIVERY => 'IDL',
        Accessorial::LIMITED_ACCESS_PICKUP => 'LTDAP',
        Accessorial::LIMITED_ACCESS_DELIVERY => 'LTDAD',
        Accessorial::APPOINTMENT => 'APTD',
        Accessorial::NOTIFY => 'MNC',
        Accessorial::HAZMAT => 'HAZ',
    ];

    /** NMFTA handling-unit codes. The pallet code is per carrier (see `$palletCode`). */
    public const PACKAGING = [
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

    /**
     * @param string $version what goes in the top-level `version`
     * @param bool $iso3 countries as USA/CAN (ODFL, R+L) rather than US/CA
     * @param string $palletCode PLT for most, PAT at SAIA
     * @param string $quoteField `quoteId`, or Estes' `quoteID`
     * @param array<string,string> $labelFormats `LabelFormat::*` => the carrier's
     *        `images.shippingLabels.format`
     */
    public function __construct(
        public string $version,
        public bool $iso3 = false,
        public string $palletCode = 'PLT',
        public string $quoteField = 'quoteId',
        public array $labelFormats = [],
    ) {
    }

    /**
     * The request body.
     *
     * @param array $units from `FreightCarrier::handlingUnits()`
     * @param string[] $codes NMFTA accessorial codes already filtered to what the carrier accepts
     */
    public function build(
        ShipmentRequest $request,
        array $units,
        array $codes,
        string $quoteId,
        string $labelFormat,
        bool $isTest,
        \DateTime $pickupDate,
        string $description,
    ): array {
        $handlingUnits = [];
        $labelCount = 0;

        foreach ($units as $unit) {
            $line = self::clean([
                'description' => $unit['description'] ?: $description,
                'weight' => $unit['weightLb'],
                'pieces' => $unit['pieces'],
                'packagingType' => $unit['packaging'] === 'pallet' ? $this->palletCode : (self::PACKAGING[$unit['packaging']] ?? $this->palletCode),
                'classification' => $unit['class'],
                'nmfc' => $unit['nmfcItem'],
                'nmfcSub' => $unit['nmfcSub'],
                'hazardous' => $unit['hazmat'],
            ]);

            $handlingUnit = [
                'count' => 1,
                'type' => $unit['packaging'] === 'pallet' ? $this->palletCode : (self::PACKAGING[$unit['packaging']] ?? $this->palletCode),
                'weight' => $unit['weightLb'],
                'weightUnit' => 'Pounds',
            ];

            if ($unit['hasDims']) {
                $handlingUnit += [
                    'length' => $unit['lengthIn'],
                    'width' => $unit['widthIn'],
                    'height' => $unit['heightIn'],
                    'dimensionsUnit' => 'Inches',
                ];
            }

            $handlingUnit['stackable'] = false;
            $handlingUnit['lineItems'] = [$line];
            $handlingUnits[] = $handlingUnit;
            $labelCount++;
        }

        $body = [
            'version' => $this->version,
            'bol' => self::clean([
                'function' => 'Create',
                'requestedPickupDate' => $pickupDate->format('Y-m-d') . 'T09:00:00.000',
                'isTest' => $isTest,
                'requestorRole' => 'Shipper',
                'specialInstructions' => mb_substr($request->instructions, 0, 250),
            ]),
            'payment' => ['terms' => 'Prepaid'],
            'commodities' => [
                'lineItemLayout' => 'Nested',
                'handlingUnits' => $handlingUnits,
            ],
            'origin' => $this->party($request->shipFrom),
            'destination' => $this->party($request->shipTo),
            'billTo' => $this->party($request->shipFrom),
            'referenceNumbers' => self::clean([
                $this->quoteField => $quoteId,
                'bol' => $request->reference !== '' ? [$request->reference] : [],
                'po' => $request->reference2 !== '' ? [['number' => $request->reference2]] : [],
            ]),
            'images' => [
                'includeBol' => true,
                'includeShippingLabels' => true,
                'shippingLabels' => [
                    'format' => $this->labelFormats[$labelFormat] ?? (reset($this->labelFormats) ?: 'Avery'),
                    'quantity' => max(1, min(100, $labelCount)),
                    'position' => 1,
                ],
            ],
        ];

        if ($codes !== []) {
            $body['accessorials'] = ['codes' => array_values($codes)];

            if (in_array('HAZ', $codes, true)) {
                $body['accessorials']['hazardousDetails'] = [
                    'emergencyContact' => self::clean([
                        'name' => $request->shipFrom?->contactName() ?? '',
                        'phone' => self::phone($request->shipFrom?->phone ?? ''),
                    ]),
                ];
            }
        }

        return $body;
    }

    /**
     * What came back. `status` is PASS, WARNING, FAIL or '' when the carrier sent no
     * `messageStatus` at all.
     *
     * @return array{pro:string, bol:mixed, labels:list<string>, status:string, message:string,
     *     warnings:list<string>, quoteId:string}
     */
    public static function parse(Response $response): array
    {
        $status = $response->at('messageStatus.status');
        $message = '';
        $warnings = [];

        if (is_array($response->at('messageStatus'))) {
            $message = trim(implode(' ', array_filter([
                (string)$response->at('messageStatus.message', ''),
                (string)$response->at('messageStatus.resolution', ''),
            ])));

            foreach ((array)$response->at('messageStatus.information', []) as $info) {
                if (is_array($info) && !empty($info['message'])) {
                    // Estes marks each item E or W; an E on a 2xx still means the BOL failed.
                    if (strtoupper((string)($info['type'] ?? '')) === 'E' && $message === '') {
                        $message = (string)$info['message'];
                    }

                    $warnings[] = (string)$info['message'];
                }
            }
        } elseif (is_string($response->at('messageStatus'))) {
            $message = (string)$response->at('messageStatus');
        }

        // ODFL's field table says string, its sample sends 77777777777 as a number.
        // R+L's DigitalCouncilBOL may answer in its own envelope (`ProNumber`) rather than NMFTA's.
        $pro = $response->at('referenceNumbers.pro') ?? $response->at('ProNumber') ?? $response->at('proNumber');
        $pro = is_int($pro) || is_float($pro) ? sprintf('%.0f', $pro) : trim((string)$pro);

        $labels = $response->at('images.shippingLabels');
        $labels = is_array($labels) ? array_values(array_filter($labels, 'is_string')) : (is_string($labels) && $labels !== '' ? [$labels] : []);

        return [
            'pro' => $pro,
            'bol' => $response->at('images.bol'),
            'labels' => $labels,
            'status' => strtoupper(is_string($status) ? $status : ''),
            'message' => $message,
            'warnings' => $warnings,
            'quoteId' => (string)($response->at('referenceNumbers.quoteId') ?? $response->at('referenceNumbers.quoteID') ?? ''),
        ];
    }

    private function party(?Address $address): array
    {
        if ($address === null) {
            return [];
        }

        $country = strtoupper($address->countryCode);

        return [
            'address' => self::clean([
                'name' => mb_substr($address->company !== '' ? $address->company : $address->contactName(), 0, 60),
                'address1' => mb_substr($address->streetLine(), 0, 60),
                'address2' => mb_substr($address->street2, 0, 60),
                'city' => $address->city,
                'stateProvince' => $address->region,
                'postalCode' => $country === 'US' && preg_match('/^(\d{5})/', $address->postcode, $m) ? $m[1] : $address->postcode,
                'country' => $this->iso3 ? match ($country) {
                    'US' => 'USA',
                    'CA' => 'CAN',
                    'MX' => 'MEX',
                    default => $country,
                } : $country,
            ]),
            'contact' => self::clean([
                'name' => mb_substr($address->contactName(), 0, 60),
                'phoneNumber' => self::phone($address->phone),
                'email' => $address->email,
            ]),
        ];
    }

    private static function phone(string $phone): string
    {
        $digits = (string)preg_replace('/\D/', '', $phone);

        return strlen($digits) === 11 && str_starts_with($digits, '1') ? substr($digits, 1) : $digits;
    }

    /**
     * Drop empty strings, empty arrays and nulls. These validators reject an empty string where
     * they would happily accept a missing key.
     */
    public static function clean(array $values): array
    {
        return array_filter($values, static fn($v) => $v !== null && $v !== '' && $v !== []);
    }
}
