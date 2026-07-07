# pccustomerid — PC Customer ID for customers adress

Generic PrestaShop 8.2 module, multistore/multilanguage. Adds a conditional
`dni` field (ID number / DNI or equivalent) to customer address forms,
required only for addresses in an admin-configurable set of countries, with
a built-in extra rule for the Canary Islands (Spain). It is not tied to any
particular store: which countries/provinces trigger the field and which
shops the module is active on are both configured from the BO, with no
values hardcoded in code.

## What it does

- Registers four native hooks, no core override, no theme override:
  - `additionalCustomerAddressFields` — declares the `dni` field (hidden and
    optional by default; visibility/required state is driven by JS).
  - `actionValidateCustomerAddressForm` — the actual server-side enforcement:
    rejects the address form if the destination requires the ID and `dni` is
    empty, too long, or contains disallowed characters.
  - `displayAdditionalCustomerAddressFields` — shows the saved ID number on
    the "my addresses" cards.
  - `actionFrontControllerSetMedia` — loads `front.css` / `front.js` only on
    the `address`, `addresses`, `order` and `authentication` controllers, and
    hands the JS its configuration via `Media::addJsDef`.
- Stores the value in the native `ps_address.dni` column (the FormField's
  name is `dni`, which `CustomerAddressForm::submit()` maps directly onto
  `Address::$dni` — verified against PrestaShop 8.2 core source, no custom
  table, no `actionObjectAddressAddAfter` fallback needed).
- Does **not** enable PrestaShop's global "identification number required"
  country setting — which countries/regions require the ID is entirely this
  module's own, admin-configurable logic (`Pccustomerid::requiresCustomerId()`).

## Why the field is never marked "required" at the FormField level

PrestaShop's own `AbstractForm::validate()` already rejects empty required
fields with a generic, non-localized message before our hook even has a
chance to run its own check. To show the exact error text requested by the
client ("ID number is required for this shipping destination.") without a
duplicate generic message underneath it, the field is always declared
`required = false` at the PHP level. The HTML `required` attribute and the
red asterisk are added/removed reactively by `front.js` for UX only; the
actual rejection always happens server-side in
`hookActionValidateCustomerAddressForm()`, regardless of JS.

## Value format

The accepted character set (`^[0-9A-Za-z\-.]{1,16}$`) is intentionally
narrower than the cahier des charges' suggested regex: it matches exactly
`Address::$definition['fields']['dni']['validate']` (`Validate::isDniLite()`)
in PrestaShop core. Accepting spaces, slashes or accented letters here would
let a value pass our own check and then throw a fatal validation exception
when `Address::save()` runs its own native check.

## Configuration (Admin > Modules > PC Customer ID for customers adress)

The BO page is built with the standard `HelperForm` (same widgets/markup as
any other native PrestaShop module settings page — no hand-rolled HTML):

- **Shops** — a shop-association tree (`HelperTreeShops`, the exact same
  checkbox component PrestaShop uses elsewhere for per-shop association,
  e.g. products/carriers). Check a shop to make the module active there;
  uncheck it to fully disable the module for that shop. There is no
  separate "excluded shops" text field — this tree is the only on/off
  switch, and it lists every shop regardless of which BO shop-context
  selector is active, exactly like other native shop-association screens.
- **Countries** — a real multi-select (`Country::getCountries()`) of every
  active country in the shop. Any country picked here always requires the
  ID number, for any store using this module — nothing is hardcoded to
  Switzerland or any other country.
- **Canary Islands provinces (Spain)** — a multi-select of Spain's actual
  provinces (`State::getStatesByIdCountry()`), not a manually-typed list of
  state IDs. Spain itself is resolved on the fly via `Country::getByIso('ES')`,
  so there is no "Spain country ID" field to fill in either. This is kept as
  a dedicated rule (in addition to the generic country list above) because
  it targets part of a country, not the whole of Spain.
- **Postcode fallback (regex)** — used only when the province above can't be
  matched (e.g. state data not imported yet); defaults to `^(35|38)\d{3}$`.
- **Run auto-detection** button — re-detects the Canary provinces from
  `ps_state` (`name` LIKE `%Canar%`/`%Palmas%`/`%Tenerife%` for ISO `ES`);
  useful after a fresh localization import on a new environment.

All settings are stored as simple global `Configuration` values (not scoped
per shop-context) since the shop tree is itself the per-shop mechanism —
this matches how PrestaShop's own shop-association settings work and avoids
mixing two different multistore models on one page.

| Key | Stores | Default |
|---|---|---|
| `PCCUSTOMERID_ENABLED_SHOP_IDS` | CSV of `id_shop` where the module is active | every shop except one whose domain contains `seedstockersusa.com` (detected at install; edit the tree to change) |
| `PCCUSTOMERID_COUNTRIES` | CSV of `id_country` that always require the ID | Switzerland only |
| `PCCUSTOMERID_CANARY_STATE_IDS` | CSV of `id_state` (Spain provinces) | auto-detected Canary provinces |
| `PCCUSTOMERID_CANARY_POSTCODE_REGEX` | regex string | `^(35|38)\d{3}$` |
| `PCCUSTOMERID_USE_DNI_FIELD` | `1`/`0` | `1` (informational — see below) |

Because staging and production have different database IDs, install this
module on each environment separately (or re-run "Run auto-detection" after
copying a database) rather than copying configuration values across
environments — the multi-select/tree UI is precisely what makes that safe:
you pick countries/shops by name, not by typing an ID that may differ
between environments.

## Installation

1. Copy `modules/pccustomerid/` into your PrestaShop `modules/` directory.
2. Admin > Modules > install "PC Customer ID for customers adress".
3. Clear the cache (Admin > Advanced Parameters > Performance, or delete
   `var/cache/*`).
4. Open the module's configuration and confirm: the shop tree matches which
   shops should have the field, the countries multi-select has the right
   countries checked (Switzerland by default), and the Canary provinces
   multi-select looks correct for your `ps_state` data.

Uninstalling removes the module's configuration keys only; any `dni` value
already saved on customer addresses is left untouched.

## Translations

- `translations/es-ES/ModulesPccustomeridShop.es-ES.xlf` and the identical
  `translations/es/ModulesPccustomeridShop.es.xlf` carry the Spanish label,
  placeholder, error message and long customs notice supplied by the client.
- Every other active language (`de`, `fr`, `it`, `nl`, `pl`, `cs`, `ru`, `ca`,
  `en`) intentionally has no translation file: all `trans()` calls use the
  English text as translation ID, and PrestaShop falls back to that ID
  verbatim when no catalog entry exists — which is exactly the English text
  the client asked for on those shops/languages. No translation should be
  invented for the long customs notice beyond the two variants supplied.
- Admin domain (`Modules.Pccustomerid.Admin`) and the config page labels
  (`{l ... mod='pccustomerid'}`) are English-only; add legacy
  `translations/<iso>.php` files if BO staff need another language.

## Manual test plan (staging)

Covers the acceptance criteria in the cahier des charges section 13/14:

1. Checkout / account, address to France, Italy, Germany → field hidden,
   optional, submits fine empty.
2. Checkout / account, address to Switzerland → field visible, required;
   submitting empty is rejected with the client-specified error message.
3. Spain + Madrid → hidden/optional. Spain + Las Palmas or Santa Cruz de
   Tenerife (by state, and again by postcode `35001`/`38001`) → visible and
   required. Spain + postcode `28001` → hidden/optional.
4. Disable JavaScript and repeat the Switzerland/Canary/France cases: the
   field has no visual cue, but submission is still rejected/accepted
   correctly server-side.
5. Switch language/shop to `es` and confirm the Spanish help text under the
   field; any other shop/language shows the English text.
6. Confirm the module is inactive by default on the shop whose domain is
   `seedstockersusa.com` (unchecked in the shop tree), and that checking it
   in the BO re-enables the module there without affecting other shops.
7. Install/uninstall/reinstall without errors; verify hooks are registered
   (Advanced Parameters > Hooks, filter by module) and no core/theme file
   was modified.
