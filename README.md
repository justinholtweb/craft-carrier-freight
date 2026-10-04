# Carrier for LTL Freight

US less-than-truckload freight for [Carrier](https://justinholt.com/plugins/craft-carrier), the shipping-carrier gateway for Craft Commerce 5. This package covers six carriers: TForce Freight, Old Dominion (ODFL), Estes Express, XPO, R+L Carriers and SAIA.

This is an add-on. Carrier handles packing, checkout, label storage, retries, the log and tracking. This package translates each carrier's API into Carrier's documents and does nothing else.

## Requirements

- Craft CMS 5.3 or later, Craft Commerce 5
- Carrier 5.0 or later

## What each carrier does

| | Quotes | Bill of lading | Labels | Void | Pickups | Tracking | Test environment |
|---|---|---|---|---|---|---|---|
| TForce Freight | yes | yes | Avery PDF, 4×6 PDF, ZPL | — | yes | yes | yes (CIE) |
| Old Dominion | yes | NMFTA eBOL | Avery PDF | yes | yes | yes | yes (QA host) |
| Estes Express | yes | NMFTA eBOL | Avery PDF, 4×6 PDF | — | — | yes | yes (UAT host) |
| XPO | yes | yes | Avery PDF | yes | — | yes | test mode on BOLs only |
| R+L Carriers | yes | NMFTA eBOL | Avery PDF | — | — | yes | none |
| SAIA | yes (unverified) | NMFTA eBOL | Avery PDF, 4×6 PDF | — | — | yes (unverified) | yes (pilot gateway) |

Services:

- TForce: standard LTL, guaranteed, accelerated guaranteed.
- Old Dominion: standard LTL, guaranteed by 5 PM, guaranteed by noon.
- XPO: standard LTL, guaranteed.
- Estes, R+L and SAIA: standard LTL only. Their guaranteed and volume levels need data a Commerce order doesn't carry (tare weight, lineal feet) or use codes the carriers don't publish.

## How freight maps onto Carrier

- **Each parcel is one handling unit** (a pallet, a crate or a drum). Weight goes out in whole pounds and dimensions in whole inches, both rounded up so the shipment is never under-declared. Set the freight-specific values on the parcel: `packagingType`, `pieces`, `freightClass`, `nmfc` and `hazmat`.
- **Freight class is a string.** "77.5" and "92.50" are both accepted. "77" is refused because it isn't an NMFC class. A parcel without a class uses the connection's *Default freight class*. If that is also empty, the shipment is refused and the merchant is told which handling unit is missing a class. Nothing is guessed.
- **The bill of lading is the purchase.** Creating a shipment creates a BOL. The BOL PDF is stored as a document of kind `bol`, and any shipping labels as kind `label`.
- **The PRO is the tracking number.** There is one PRO per shipment however many pallets it has, so no carrier here declares multi-piece.
- **Accessorials.** Each carrier declares only the accessorials it can both price and print on the BOL. A liftgate that was quoted but left off the BOL (or the reverse) is how merchants get surprise invoices.
- **Residential** comes from the `residentialDelivery` and `residentialPickup` accessorials only. It never comes from the address's residential flag, because that flag defaults to true for checkout addresses and LTL carriers charge for it.
- **Quote numbers.** Old Dominion, Estes, R+L and SAIA honour a quoted price only when the BOL cites the quote number. Carrier doesn't yet pass the checkout quote through to label time, so these carriers request a fresh quote for the shipped service when the BOL is created and cite that number. To skip the re-quote, pass `options['quoteId']` on the request.
- **A BOL that exists but can't be completed is uncertain, never a refusal.** Examples: XPO accepts the BOL but has not assigned a PRO yet, or a carrier returns a PRO with no readable PDF. Carrier parks these shipments for a person to check rather than buying a second BOL.

## Credentials

| Carrier | What you need | Where |
|---|---|---|
| TForce | Client ID and secret (Azure AD client credentials) | developer.tforcefreight.com → Profile → Configure My Client |
| Old Dominion | ODFL.com username and password | ODFL.com; QA access from API@odfl.com |
| Estes | API key, plus MyEstes username and password | WebSupport@estes-express.com issues a client ID and secret. Call `POST /v1/api-key` once per environment to create the key. |
| XPO | Consumer key and secret, plus XPO LTL web username and password | LTLWebAPISupport@xpo.com |
| R+L | API key | rlcarriers.com → Resources → API Overview |
| SAIA | Saia Secure username and password, plus three product keys (Rate Quote, Tracking, Bill of Lading) | SAIA's developer portal; each product has its own key |

Store every secret in an environment variable.

## Unverified

These connectors were written from the carriers' published documentation, without live accounts. Anything that couldn't be confirmed from a primary source is marked **UNVERIFIED** in the docblock of the carrier class, where someone debugging that carrier will look. The most important items:

- **SAIA rating and tracking.** SAIA doesn't publish the REST response schemas. The parsers use the legacy SOAP field names (`QuoteNumber`, `TotalInvoice`, `StandardServiceDays`) and look for status words. SAIA's REST rating accessorial codes are also unpublished, so SAIA offers no accessorials. Capture real responses from the pilot gateway before relying on SAIA rates.
- **The NMFTA eBOL party objects.** No primary source seen shows the exact nesting of `origin`, `destination` and `billTo`. The builder follows the property names in Estes' swagger. Validate it once per carrier in their test environment (`bol.isTest: true` validates without creating anything).
- **SAIA's pallet code** (`PAT`), and whether R+L's `DigitalCouncilBOL` answers in NMFTA's shape or R+L's own. Both are handled.
- **TForce BOL party fields.** TForce rejects any property it doesn't recognise, so test in CIE first. Also unverified: the liftgate and limited-access delivery codes, which TForce's manuals contradict each other on (`LIFD` and `LADL`).
- **ODFL.** Whether rating accepts a decimal `freightClass` such as 77.5, the key the session token comes back under, and the HTTP method of the BOL void.
- **XPO.** When an auto-assigned PRO becomes readable, and limited access in rating, which is therefore not offered.
- **Pickups for Estes, XPO and R+L.** The endpoints exist but their request bodies aren't documented in the sources used, so pickups aren't implemented for these three.
- **Public tracking page URLs** for all six carriers.

## Testing

The conformance suite drives all six carriers through Carrier's contract against the recorded samples in `tests/fixtures.php`:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-carrier/tests/integration/carriers.php freight-tforce freight-odfl freight-estes freight-xpo freight-rl freight-saia
```

## Licence

The Craft License. See [LICENSE.md](LICENSE.md). Carrier for LTL Freight is free: no editions, no licence key, and no licensing code in the plugin. It needs [Carrier](https://justinholt.com/plugins/craft-carrier), which is commercial.
