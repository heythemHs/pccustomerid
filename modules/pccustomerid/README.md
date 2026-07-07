# pccustomerid — PC Customer ID for customers adress

PrestaShop 8.2 module for the Seedstockers multistore installation. Adds a
conditional `dni` field (ID number / DNI or equivalent) to customer address
forms, required only when the shipping destination is Switzerland or the
Canary Islands (Spain).

## What it does

- Registers four native hooks, no core override, no theme override:
  - `additionalCustomerAddressFields` — declares the `dni` field (hidden and
    optional by default; visibility/required state is driven by JS).
  - `actionValidateCustomerAddressForm` — the actual server-side enforcement:
    rejects the address form if the destination is Switzerland or the Canary
    Islands and `dni` is empty, too long, or contains disallowed characters.
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
  country setting for CH or ES — the Canary-only / Switzerland-only rule is
  entirely this module's own logic (`Pccustomerid::requiresCustomerId()`).

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

All settings are read through `Configuration::get($key)` with no explicit
`id_shop`, so they resolve via PrestaShop's normal multistore cascade
(shop → shop group → all shops) for the shop context the visitor is
currently on. The BO configuration page respects whichever shop context
(`All shops` / `Shop group` / `Single shop`) is selected in the top page
selector when you hit Save.

| Key | Default | Notes |
|---|---|---|
| `PCCUSTOMERID_ENABLED` | `1` (`0` on the auto-detected USA shop) | Master on/off for the current shop context. |
| `PCCUSTOMERID_EXCLUDED_SHOP_IDS` | auto-filled with the shop ID(s) whose domain contains `seedstockersusa.com` | Belt-and-suspenders exclusion list, independent of the per-shop toggle above. |
| `PCCUSTOMERID_CH_COUNTRY_ID` | auto-detected via ISO `CH` | |
| `PCCUSTOMERID_ES_COUNTRY_ID` | auto-detected via ISO `ES` | |
| `PCCUSTOMERID_CANARY_STATE_IDS` | auto-detected (`ps_state.name` LIKE `%Canar%`/`%Palmas%`/`%Tenerife%` for ES) | Editable CSV; re-run detection any time with the "Run auto-detection" button. |
| `PCCUSTOMERID_CANARY_POSTCODE_REGEX` | `^(35|38)\d{3}$` | Fallback used when the state can't be matched. |
| `PCCUSTOMERID_USE_DNI_FIELD` | `1` | Informational only in this version — the module always uses the native `dni` column. |

Because staging and production have different database IDs, install this
module on each environment separately (or re-run "Run auto-detection" after
copying a database) rather than copying configuration values across
environments.

## Installation

1. Copy `modules/pccustomerid/` into your PrestaShop `modules/` directory.
2. Admin > Modules > install "PC Customer ID for customers adress".
3. Clear the cache (Admin > Advanced Parameters > Performance, or delete
   `var/cache/*`).
4. Open the module's configuration and confirm the auto-detected country /
   Canary state IDs, and that the USA shop is excluded.

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
   `seedstockersusa.com`, and that toggling `PCCUSTOMERID_ENABLED` in that
   shop's own context re-enables it without affecting other shops.
7. Install/uninstall/reinstall without errors; verify hooks are registered
   (Advanced Parameters > Hooks, filter by module) and no core/theme file
   was modified.
