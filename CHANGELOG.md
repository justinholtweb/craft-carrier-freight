# Release Notes for Carrier for LTL Freight

## 5.0.0 - 2026-10-03

### Added
- TForce Freight: LTL quotes for standard, guaranteed and accelerated guaranteed service, bills of lading with Avery PDF, 4×6 PDF or ZPL labels, pickups and PRO tracking.
- Old Dominion: LTL quotes for standard, guaranteed-by-5-PM and guaranteed-by-noon service, NMFTA electronic bills of lading, BOL voids, pickups and PRO tracking.
- Estes Express: LTL quotes, NMFTA electronic bills of lading with Avery or 4×6 labels, and PRO tracking.
- XPO: LTL quotes for standard and guaranteed service, bills of lading with shipping labels, BOL cancellation and PRO tracking.
- R+L Carriers: LTL quotes, NMFTA electronic bills of lading with labels, and PRO tracking.
- SAIA: LTL quotes, NMFTA electronic bills of lading with Avery or 4×6 labels, and PRO tracking. SAIA's rating and tracking response formats are unpublished, so those two parsers are unverified.
- One shared NMFTA Digital LTL Council eBOL builder for Old Dominion, Estes, SAIA and R+L.
- Handling units from Carrier's parcels: whole pounds and inches rounded up, NMFC class kept as a string, NMFC item and sub split with the sub's leading zero kept.
- A default freight class and commodity description on every freight connection.
- Re-quoting at BOL time for carriers that only honour a quote cited on the BOL (Old Dominion, Estes, R+L, SAIA). Pass `options['quoteId']` to skip it.
