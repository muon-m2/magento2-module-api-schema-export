# Muon_ApiSchemaExport

Generates API description files — JetBrains/VS Code `.http`, Postman v2.1, OpenAPI 3.1 and
Swagger 2.0 — for a chosen set of Magento modules.

## Why it exists

Magento declares its REST surface across dozens of per-module `etc/webapi.xml` files, then merges
them into one route table that no longer records which module declared what. The built-in
`/rest/all/schema` endpoint therefore serves a single whole-installation document with no module
attribution, filtered by the caller's ACL. This module restores the attribution and turns any
selection of modules into something you can hand to an integrator.

## Installation

```bash
bin/magento module:enable Muon_ApiSchemaExport
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

No database changes: the module is stateless.

## Usage

```bash
bin/magento muon:api-schema:export [modules...] [options]
```

| Input | Purpose |
|---|---|
| `modules` (argument, repeatable) | Selectors — exact (`Muon_FileAttachment`), vendor namespace (`Muon`), wildcard (`Magento_Catalog*`), or `*` |
| `--format`, `-f` (repeatable) | `http`, `postman`, `openapi`, `swagger`, or `all` |
| `--output`, `-o` | Output directory, default `var/api-schema-export` |
| `--filename` | Base name; derived from the selection when omitted |
| `--base-url` | Overrides the default store view's base URL |
| `--yaml` | YAML instead of JSON for `openapi` and `swagger` |
| `--split` | One file per module |
| `--no-async` | Omit the `/async` and `/async/bulk` variants |
| `--stdout` | Write to stdout; rejected when the request would produce more than one file |

Omit `modules` or `--format` on an interactive terminal and the command prompts. Under
`--no-interaction` a missing value fails with exit `1` rather than blocking — a command that
prompts into a closed stdin hangs a CI job until something kills it.

| Exit code | Meaning |
|---|---|
| `0` | Files written, or document printed |
| `1` | Invalid input, or a required value missing non-interactively |
| `2` | A selector matched no enabled module |

### Examples

```bash
# Everything one vendor exposes, in all four formats
bin/magento muon:api-schema:export Muon --format=all

# One module as OpenAPI YAML
bin/magento muon:api-schema:export Muon_FileAttachment --format=openapi --yaml

# A wildcard, one document per module
bin/magento muon:api-schema:export "Magento_Catalog*" --format=openapi --split
```

For the admin screen, install `Muon_ApiSchemaExportAdminUi` and use **System → API Schema Export**.

## Generated artifacts carry no credentials

Every format emits authentication as a placeholder — `{{token}}` in the `.http` and Postman files,
a `bearerAuth` security scheme in OpenAPI and Swagger. The companion `<name>.env.json` and
Postman environment ship `token = ""`. The files are meant to be shared, so a renderer that
embedded a live token would turn each one into a credential leak; unit tests assert the property
per renderer on every run.

## Documentation

- [Technical reference](docs/technical-reference.md)
- [Developer guide](docs/developer-guide.md)
- [CHANGELOG](CHANGELOG.md)

## Compatibility

| | Magento 2.4.7 | Magento 2.4.8 | Magento 2.4.9 |
|---|---|---|---|
| Supported | yes | yes | yes (developed against) |
| `magento/framework` | 103.0.7 | 103.0.8 | 103.0.9 |
| `magento/module-webapi` | 100.4.6 | 100.4.7 | 100.4.8 |
| PHP | 8.1 – 8.3 | 8.2 – 8.4 | 8.3 – 8.5 |

PHP floor is **8.1** — the code uses readonly promoted properties and nothing newer, confirmed with
PHPCompatibility. Every framework API used was verified present at each release tag, and the
constraint set was resolved against each real product metapackage.

**On 2.4.7:** Magento ships PHPUnit 9.5 there, which has no attribute support, so the unit tests
declare each data provider with both the `#[DataProvider]` attribute and the `@dataProvider`
annotation. The suite therefore runs unchanged on PHPUnit 9.5, 10.5 and 12. Production code is
unaffected either way.

Note also that Composer blocks the **unpatched** `2.4.7` release under security advisory
`PKSA-db8d-773v-rd1n` — a property of that Magento release, not of this module. Use a `2.4.7-pN`
patch release.

## Requirements

PHP ~8.1 – ~8.5 · `magento/framework` ^103.0.7 · `magento/module-webapi` ^100.4.6 ·
`magento/module-store` ^101.1.7 · `symfony/yaml` ^6.4 || ^7.0 || ^8.0

`magento/module-webapi-async` is suggested, not required: the `/async` and `/async/bulk` variants
are emitted only when it is enabled.

## License

OSL-3.0 — see [LICENSE.txt](LICENSE.txt).
