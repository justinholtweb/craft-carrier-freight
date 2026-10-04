<?php
/**
 * Recorded happy-path answers for the conformance suite
 * (craft-carrier/tests/integration/carriers.php).
 *
 * Each answer is the carrier's own documented sample, trimmed but kept in its real shape:
 * TForce's Rating/Shipping/Tracking manuals, ODFL's development guides, Estes' swagger and
 * developer site, XPO's implementation guides, R+L's API documentation. Two exceptions, flagged
 * where they occur because no sample exists to copy:
 * - SAIA's REST rating and tracking answers (unpublished): legacy SOAP field names in camelCase.
 * - R+L's DigitalCouncilBOL answer (not shown): R+L's own BillOfLading envelope (`ProNumber`),
 *   which also exercises the PrintBOL / PrintShippingLabels fallback.
 *
 * `Pdf::text()` stands in for the base64 PDFs the carriers return.
 */

use justinholtweb\carrier\base\Response;
use justinholtweb\carrier\helpers\Pdf;

// Wrapped so the helpers below do not leak into the suite's global scope.
return (static function(): array {
$pdf = static fn(string ...$lines) => base64_encode(Pdf::text($lines, 612.0, 792.0));
$label = static fn(string ...$lines) => base64_encode(Pdf::text($lines));
$has = static fn(string $url, string $needle) => str_contains($url, $needle);

return [

    // =========================================================================
    'freight-tforce' => static function(string $method, string $url, array $options) use ($pdf, $label, $has): Response {
        if ($has($url, 'login.microsoftonline.com')) {
            return Response::json(200, ['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.tforce']);
        }

        if ($has($url, '/rating/getRate')) {
            $service = (string)($options['json']['requestOptions']['serviceCode'] ?? '308');

            return Response::json(200, [
                'summary' => [
                    'transactionReference' => ['transactionId' => 'f2e1c7a0', 'customerContext' => 'TCK-1001'],
                    'responseStatus' => ['code' => 'OK', 'message' => 'Success'],
                    'quoteNumber' => '5V5U1B4MN',
                ],
                'detail' => [[
                    'service' => ['code' => $service, 'description' => 'TForce Freight LTL'],
                    'alerts' => [['code' => '853', 'message' => 'FBR853: DESTINATION IS A RURAL POINT']],
                    'rate' => [
                        ['code' => 'LND_GROSS', 'description' => 'LND_GROSS', 'value' => '2140.38', 'unit' => 'USD'],
                        ['code' => 'DSCNT', 'description' => 'DSCNT', 'value' => '-1006.0', 'unit' => 'USD'],
                        ['code' => 'FUEL_SUR', 'description' => 'FUEL_SUR', 'value' => '417.15', 'unit' => 'USD'],
                    ],
                    'shipmentCharges' => ['total' => ['currency' => 'USD', 'value' => $service === '308' ? '1551.53' : '1798.20']],
                    'shipmentWeights' => ['billable' => ['unit' => 'LBS', 'value' => '800']],
                    'timeInTransit' => ['unit' => 'DAY', 'value' => '3'],
                ]],
            ]);
        }

        if ($has($url, '/shipping/bol/create')) {
            return Response::json(200, [
                'summary' => ['responseStatus' => ['code' => 'OK', 'message' => 'Success']],
                'detail' => [
                    'bolId' => 46146071,
                    'pro' => '216413921',
                    'rateDetail' => [],
                    'timeInTransit' => ['unit' => 'DAY', 'timeInTransit' => '3'],
                    'pickup' => ['transactionReference' => ['confirmationNumber' => 'WBU5353360']],
                    'documents' => ['image' => [
                        ['type' => '20', 'format' => '01', 'data' => $pdf('TFORCE FREIGHT', 'STRAIGHT BILL OF LADING', 'PRO 216413921')],
                        ['type' => '30', 'format' => '01', 'data' => $pdf('TFORCE FREIGHT', 'PRO 216413921', 'LABEL 1 OF 2')],
                    ]],
                ],
            ]);
        }

        if ($has($url, '/track/pro/')) {
            return Response::json(200, [
                'summary' => ['responseStatus' => ['code' => 'OK', 'message' => 'Success']],
                'detail' => [[
                    'pro' => '216413921',
                    'currentStatus' => ['code' => 'DL', 'description' => 'Delivered', 'details' => '011'],
                    'delivery' => [
                        'estimated' => ['date' => '2026-10-07'],
                        'actual' => ['date' => '2026-10-07T14:02:00-04:00'],
                        'signedBy' => 'J SMITH',
                    ],
                    'events' => [
                        ['date' => '2026-10-07T14:02:00-04:00', 'description' => 'Delivered', 'serviceCenter' => 'Greenville, SC', 'trailer' => ['number' => '123456', 'scac' => 'TFIN']],
                        ['date' => '2026-10-07T07:40:00-04:00', 'description' => 'Out for delivery', 'serviceCenter' => 'Greenville, SC'],
                        ['date' => '2026-10-05T18:15:00-04:00', 'description' => 'Departed origin service center', 'serviceCenter' => 'Richmond, VA'],
                    ],
                ]],
            ]);
        }

        if ($has($url, '/pickup/request')) {
            return Response::json(201, [
                'transactionReference' => ['confirmationNumber' => 'WBU5353360'],
                'originIsRural' => false,
                'destinationIsRural' => false,
            ]);
        }

        return Response::json(404, ['summary' => ['responseStatus' => ['code' => 'NFO', 'message' => 'Not found']]]);
    },

    // =========================================================================
    'freight-odfl' => static function(string $method, string $url, array $options) use ($pdf, $label, $has): Response {
        if ($has($url, '/auth/v1.0/token')) {
            return Response::json(200, ['sessionToken' => 'odfl-session-8c41', 'expiration' => '2026-10-04T10:00:00Z']);
        }

        if ($has($url, '/rating-service/v1.0/rates')) {
            $type = (string)($options['json']['requestedRates'][0]['serviceType'] ?? 'LTL');

            return Response::json(200, [
                'success' => true,
                'rateRequestId' => '3f0c8a52-6a0e-4a6b-9d1f-0f1d2e3c4b5a',
                'rateEstimateNumber' => 41325874,
                'directDelivery' => true,
                'serviceDays' => [['days' => 3, 'city' => 'EASLEY']],
                'requestedRates' => [[
                    'serviceType' => $type,
                    'rateType' => 'DEFAULT',
                    'detailId' => 1,
                    'totalCharge' => $type === 'LTL' ? 412.37 : 489.10,
                    'netFreightCharges' => $type === 'LTL' ? 412.37 : 489.10,
                    'grossFreightCharges' => 1240.11,
                    'fuelSurcharge' => 96.48,
                    'discountAmount' => 924.22,
                    'discountPercentage' => 74.5,
                    'accessorialCharges' => [],
                    'rateExpirationDate' => '2026-11-03',
                    'errorMessages' => [],
                    'informationMessages' => [],
                ]],
            ]);
        }

        if ($has($url, '/eBOL/bol-external-per-standards')) {
            return Response::json(200, [
                'referenceNumbers' => ['pro' => 77777777777, 'quoteId' => '41325874'],
                'images' => [
                    'bol' => $pdf('OLD DOMINION FREIGHT LINE', 'BILL OF LADING', 'PRO 07777777777'),
                    'shippingLabels' => $pdf('ODFL', 'PRO 07777777777', 'AVERY LABELS'),
                ],
                'messageStatus' => ['status' => 'PASS', 'code' => '200', 'message' => 'BOL created successfully', 'resolution' => '', 'information' => []],
                'resultStatusCodes' => [],
            ]);
        }

        if ($has($url, 'deleteBolPro')) {
            return Response::json(200, ['messageStatus' => ['status' => 'PASS', 'code' => '200', 'message' => 'BOL deleted']]);
        }

        if ($has($url, '/pickup/v3.0/create')) {
            return Response::json(200, ['response' => ['pickupNumber' => 30071234, 'status' => 'Pickup Requested']]);
        }

        if ($has($url, '/tracking/v2.0/shipment.track')) {
            return Response::json(200, [
                'ok' => true,
                'traceInfo' => [[
                    'proNumber' => '07777777777',
                    'pieces' => 2,
                    'weight' => 800,
                    'standardEta' => '2026-10-07',
                    'updatedEta' => '2026-10-07',
                    'deliverySign' => 'SMITH',
                    'trackTraceDetail' => [
                        ['status' => 'Delivered', 'desc' => 'Delivered', 'dateTime' => '2026-10-07T14:02:00', 'city' => 'EASLEY', 'state' => 'SC', 'zipCode' => '29640'],
                        ['status' => 'Out for Delivery', 'desc' => 'Out for delivery', 'dateTime' => '2026-10-07T07:40:00', 'city' => 'GREENVILLE', 'state' => 'SC', 'zipCode' => '29605'],
                        ['status' => 'Pickup Completed', 'desc' => 'Picked up', 'dateTime' => '2026-10-04T15:20:00', 'city' => 'CHARLOTTE', 'state' => 'NC', 'zipCode' => '28202'],
                    ],
                ]],
            ]);
        }

        return Response::json(404, ['ok' => false, 'message' => 'No results found']);
    },

    // =========================================================================
    'freight-estes' => static function(string $method, string $url, array $options) use ($pdf, $label, $has): Response {
        if ($has($url, '/authenticate')) {
            return Response::json(200, ['access_token' => 'estes-bearer-55c2', 'token_type' => 'Bearer', 'expires_in' => 35999]);
        }

        if ($has($url, '/v1/rate-quotes')) {
            return Response::json(200, [
                'data' => [[
                    'serviceLevelId' => 'LTL',
                    'serviceLevelText' => 'LTL Standard Transit',
                    'quoteId' => 'LFBX7KH',
                    'rateFound' => true,
                    'dates' => ['quoteExpiration' => '2026-11-02', 'transitDeliveryDate' => '2026-10-07'],
                    'transitDetails' => ['transitDays' => 3, 'laneType' => 'DIRECT'],
                    'quoteRate' => [
                        'totalCharges' => '598.42',
                        'ratedAccessorials' => [['code' => 'FUEL', 'description' => 'Fuel Surcharge', 'charge' => '131.20']],
                    ],
                    'alerts' => [],
                    'reasons' => [],
                ]],
                'error' => ['code' => 0, 'message' => '', 'details' => ''],
            ]);
        }

        if ($has($url, '/v1/bol')) {
            return Response::json(201, [
                'referenceNumbers' => ['pro' => '0123456789', 'shipmentConfirmationNumber' => 'SC4472910'],
                'images' => [
                    'bol' => $pdf('ESTES EXPRESS LINES', 'BILL OF LADING', 'PRO 0123456789'),
                    'shippingLabels' => $pdf('ESTES', 'PRO 0123456789', 'AVERY'),
                ],
                'messageStatus' => ['status' => 'PASS', 'code' => '200', 'message' => 'Bill of lading created.', 'resolution' => '', 'information' => []],
            ]);
        }

        if ($has($url, '/v1/shipments/history')) {
            return Response::json(200, [
                'data' => [[
                    'pro' => '0123456789',
                    'status' => ['conciseStatus' => 'Delivered', 'expandedStatus' => 'Delivered to consignee', 'referenceDate' => '2026-10-07', 'referenceTime' => '14:02', 'reasonCode' => '', 'reason' => '', 'isException' => false],
                    'deliveryDate' => '2026-10-07',
                    'deliveryTime' => '14:02',
                    'receivedBy' => 'J SMITH',
                    'estimatedDelivery' => ['startDate' => '2026-10-07', 'startTime' => '08:00', 'endDate' => '2026-10-07', 'endTime' => '17:00'],
                    'transitDays' => 3,
                    'statusHistory' => [
                        ['conciseStatus' => 'Delivered', 'expandedStatus' => 'Delivered to consignee', 'referenceDate' => '2026-10-07', 'referenceTime' => '14:02', 'isException' => false],
                        ['conciseStatus' => 'Out for Delivery', 'expandedStatus' => 'Out for delivery', 'referenceDate' => '2026-10-07', 'referenceTime' => '07:40', 'isException' => false],
                        ['conciseStatus' => 'Departed Pickup Location', 'expandedStatus' => 'Departed origin terminal', 'referenceDate' => '2026-10-04', 'referenceTime' => '19:10', 'isException' => false],
                    ],
                ]],
                'error' => ['code' => 0, 'message' => '', 'details' => ''],
            ]);
        }

        return Response::json(404, ['error' => ['code' => 40400, 'message' => 'Not found', 'details' => '']]);
    },

    // =========================================================================
    'freight-xpo' => static function(string $method, string $url, array $options) use ($pdf, $label, $has): Response {
        if ($has($url, 'api.ltl.xpo.com/token')) {
            return Response::json(200, ['access_token' => 'xpo-access-91af', 'refresh_token' => 'xpo-refresh', 'scope' => 'default', 'token_type' => 'Bearer', 'expires_in' => 43200]);
        }

        if ($has($url, '/rating/1.0/ratequotes')) {
            return Response::json(200, [
                'code' => '200',
                'transactionTimestamp' => 1791100000000,
                'data' => [
                    'rateQuote' => [
                        'confirmationNbr' => 'CVM7J3C',
                        'totCharge' => [['amt' => 487.21, 'currencyCd' => 'USD']],
                        'totAccessorialAmt' => ['amt' => 96.10],
                        'totDiscountAmt' => ['amt' => 1210.55],
                        'vspApplied' => false,
                        'shipmentInfo' => [
                            'accessorials' => [['accessorialCd' => 'FSC', 'accessorialDesc' => 'Fuel Surcharge', 'chargeAmt' => ['amt' => 96.10]]],
                            'commodity' => [['nmfcClass' => '70.0']],
                        ],
                    ],
                    'transitTime' => ['transitDays' => 3, 'estdDlvrDate' => 1791417600000, 'garntInd' => false],
                    'msgs' => [],
                ],
            ]);
        }

        if ($has($url, '/cancel')) {
            return Response::json(200, ['code' => '200', 'transactionTimestamp' => 1507675675146]);
        }

        if ($has($url, '/shippinglabels/pdf')) {
            return Response::json(201, ['code' => '201', 'transactionTimestamp' => 1507234770711, 'data' => ['shippingLabel' => [
                'fileName' => 'ee3176fa0502db803numgnf01976a7_10000.pdf',
                'contentType' => $pdf('XPO', 'PRO 123456789', 'SHIPPING LABEL'),
            ]]]);
        }

        if ($has($url, '/pdf')) {
            return Response::json(200, ['code' => '200', 'transactionTimestamp' => 1509403958145, 'data' => ['bolpdf' => [
                'fileName' => '6f7ca3dc0502d8t0l7sc7cf152b023.pdf',
                'contentType' => $pdf('XPO LOGISTICS', 'BILL OF LADING', 'PRO 123456789'),
            ]]]);
        }

        if ($has($url, '/requester')) {
            return Response::json(200, ['code' => '200', 'transactionTimestamp' => 1507227085492, 'data' => ['billOfLading' => [
                'bolInstId' => '4659194715665',
                'chargeToCd' => 'P',
                'proNbr' => '123456789',
            ]]]);
        }

        if ($has($url, '/billoflading/1.0/billsoflading') && $method === 'POST') {
            return Response::json(201, ['code' => '201', 'transactionTimestamp' => 1507669461137, 'data' => ['bolInfo' => [
                'bolInstId' => '4659194715665',
                'pkupTrmnlSic' => 'XRZ',
                'pkupCallDate' => 1507618800000,
                'pkupCallSeq' => 1,
            ]]]);
        }

        if ($has($url, '/shipment-status-details')) {
            return Response::json(200, ['code' => '200', 'transactionTimestamp' => 1791400000000, 'data' => ['shipmentStatusDtls' => [[
                'proNbr' => '123456789',
                'pkupDate' => 1791100000000,
                'shipmentStatus' => ['statusCd' => '23', 'reason' => '', 'dateTime' => 1791400000000, 'description' => 'Delivered'],
                'currSic' => 'XGV',
                'origSic' => 'XCL',
                'destSic' => 'XGV',
                'estdDlvrDate' => 1791417600000,
            ]]]]);
        }

        if ($has($url, '/health-check')) {
            return Response::json(200, ['code' => '200', 'data' => ['status' => 'UP']]);
        }

        return Response::json(404, ['code' => '404', 'error' => ['errorCode' => '404', 'message' => 'Not found']]);
    },

    // =========================================================================
    'freight-rl' => static function(string $method, string $url, array $options) use ($pdf, $label, $has): Response {
        if ($has($url, '/RateQuote/GetPalletTypes')) {
            return Response::json(200, ['Code' => 200, 'Errors' => [], 'Messages' => [], 'PalletTypes' => [['Code' => '0001', 'Description' => 'Standard 48x40']]]);
        }

        if ($has($url, '/RateQuote')) {
            return Response::json(200, [
                'Code' => 200,
                'Errors' => [],
                'Messages' => [],
                'RateQuote' => [
                    'IsDirect' => true,
                    'ServiceLevels' => [
                        ['Name' => 'Standard Service', 'Title' => 'Standard Service', 'Code' => 'STD', 'QuoteNumber' => '18741102', 'ServiceDays' => 3, 'Charge' => '$1,040.12', 'NetCharge' => '$412.37'],
                        ['Name' => 'Guaranteed Hourly Window', 'Title' => 'Guaranteed Hourly Window', 'Code' => 'GSHW', 'QuoteNumber' => '18741103', 'ServiceDays' => 3, 'Charge' => '$95.00', 'NetCharge' => '$507.37'],
                    ],
                    'Charges' => [['Type' => 'GROSS', 'Title' => 'Gross Charge', 'Weight' => '800', 'Rate' => '130.01', 'Amount' => '$1,040.12']],
                    'CategorizedMessages' => ['GeneralMessages' => [], 'TransitMessages' => [], 'RatesMessages' => []],
                ],
            ]);
        }

        if ($has($url, '/BillOfLading/DigitalCouncilBOL')) {
            return Response::json(200, ['ProNumber' => 'I875682972', 'PickupRequestNumber' => '', 'Code' => 200, 'Errors' => [], 'Messages' => []]);
        }

        if ($has($url, '/BillOfLading/PrintBOL')) {
            return Response::json(200, ['Code' => 200, 'Errors' => [], 'BolDocument' => $pdf('R+L CARRIERS', 'BILL OF LADING', 'PRO I875682972')]);
        }

        if ($has($url, '/BillOfLading/PrintShippingLabels')) {
            return Response::json(200, ['Code' => 200, 'Errors' => [], 'ShippingLabelsFile' => $pdf('R+L CARRIERS', 'PRO I875682972', 'LABELS')]);
        }

        if ($has($url, '/ShipmentTracing')) {
            return Response::json(200, ['Code' => 200, 'Errors' => [], 'Shipments' => [[
                'ShipmentNumber' => 'I875682972',
                'ShortStatus' => 'Delivered',
                'LongStatus' => 'Delivered 10/07/2026 2:02 PM',
                'EstimatedDelivery' => '10/07/2026',
                'PickupDate' => ['Date' => '10/04/2026', 'Time' => '15:20'],
                'DeliveryDate' => ['Date' => '10/07/2026', 'Time' => '14:02'],
                'StatusHistory' => [
                    ['Date' => '10/07/2026', 'Time' => '14:02', 'Description' => 'Delivered', 'StatusCode' => 'DEL', 'City' => 'Easley', 'State' => 'SC', 'ZipCode' => '29640'],
                    ['Date' => '10/04/2026', 'Time' => '15:20', 'Description' => 'Picked up', 'StatusCode' => 'PU', 'City' => 'Charlotte', 'State' => 'NC', 'ZipCode' => '28202'],
                ],
                'ServiceDelayInfos' => [],
            ]]]);
        }

        return Response::json(400, ['Code' => 400, 'Errors' => [['Property' => '', 'ErrorMessage' => 'Not found', 'ExceptionMessage' => '']]]);
    },

    // =========================================================================
    'freight-saia' => static function(string $method, string $url, array $options) use ($pdf, $label, $has): Response {
        if ($has($url, '/rate-quote/health')) {
            return Response::json(200, ['status' => 'Healthy']);
        }

        // UNVERIFIED shape: SAIA's legacy SOAP CreateResult names, camelCased. See SaiaCarrier.
        if ($has($url, '/rate-quote/webservice/ratequote/customer-api')) {
            return Response::json(200, [
                'quoteNumber' => '0123456',
                'totalInvoice' => '612.88',
                'standardServiceDays' => 3,
                'estimatedDeliveryDate' => '2026-10-07',
                'fuelSurchargeAmount' => '141.02',
                'discountAmount' => '1302.50',
                'rateDetails' => [['weight' => 800, 'class' => '70', 'rate' => '211.06', 'amount' => '1688.48']],
                'rateAccessorials' => [],
                'code' => '',
                'message' => '',
            ]);
        }

        if ($has($url, '/nmfta/bol/v2.0.1/app')) {
            return Response::json(200, [
                'referenceNumbers' => ['pro' => '77049738070', 'checkDigit' => '0'],
                'images' => [
                    'bol' => $pdf('SAIA LTL FREIGHT', 'BILL OF LADING', 'PRO 77049738070'),
                    'shippingLabels' => $pdf('SAIA', 'PRO 77049738070', 'AVERY'),
                ],
                'messageStatus' => ['status' => 'PASS', 'code' => '200', 'message' => 'Success', 'resolution' => '', 'information' => []],
            ]);
        }

        // UNVERIFIED shape: no published sample.
        if ($has($url, '/customer-api/webservice/track/pro-numbers/')) {
            return Response::json(200, [
                'proNumber' => '77049738070',
                'status' => 'Delivered',
                'deliveryDate' => '2026-10-07T14:02:00',
                'signature' => 'SMITH',
                'history' => [
                    ['date' => '2026-10-07T14:02:00', 'description' => 'Delivered', 'city' => 'Easley', 'state' => 'SC'],
                    ['date' => '2026-10-04T15:20:00', 'description' => 'Picked up', 'city' => 'Charlotte', 'state' => 'NC'],
                ],
            ]);
        }

        return Response::json(404, ['statusCode' => 404, 'message' => 'Resource not found']);
    },
];
})();
