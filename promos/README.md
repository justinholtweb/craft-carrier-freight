# Plugin Store promo images

Seven 1920×1080 JPEGs for the Carrier for LTL Freight listing on the Craft Plugin Store, in the theme of
Carrier's page at [justinholt.com/plugins/craft-carrier](https://justinholt.com/plugins/craft-carrier).

```bash
./build.sh          # all slides
./build.sh "2 4"    # just slides 2 and 4
```

Output lands in `out/` as `carrier-freight-promo-N.jpg`. Promos ship as JPEG, never PNG. `fonts.css`
is generated and not checked in.

**`slides.html` is generated — don't edit it here.** Every add-on deck comes from one template in
the Carrier repo, `craft-carrier/promos/addons/generate.py`, so the eight stay consistent. Slides 2
(what it does), 3 (the connection form) and 5 (services, label formats, points) are drawn from the
carrier's own `capabilities()`, `services()` and `settingsFields()`; the rest of the copy is in
`craft-carrier/promos/addons/decks.json`. The icon is generated too, by
`craft-carrier/promos/icon-gen.py`, and carries a generic mark rather than the carrier's logo.

| # | Slide |
|---|-------|
| 1 | Cover — the add-on's icon, free, requires Carrier |
| 2 | What it does, from the declared features |
| 3 | The connection form |
| 4 | Three LTL Freight traps the add-on already handles |
| 5 | Services, label formats and pickup points |
| 6 | Built from the published API — what to check in the sandbox |
| 7 | The eight add-ons, Carrier itself, and how to install this one |
