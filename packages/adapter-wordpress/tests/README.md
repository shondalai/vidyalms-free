# WordPress template and request-input checks

These checks cover WordPress template output and request handling. For the final
package, commerce and lifecycle results, see the
[security review record](../../../docs/technical/adversarial-review-2026-10-05.md).

Run from the VidyaLMS workspace with PHP (mysqli, mbstring and DOM enabled) and
a disposable WordPress installation containing the Free plugin:

```powershell
php packages/adapter-wordpress/tests/template-output-test.php <wordpress-root>
```

The test includes the **source** template parts, using real WordPress escaping
functions. It checks raw and previously escaped mount JSON, malicious text and
URLs, allowed course formatting, artwork, pagination, nonce/referrer fields,
selected notification frequency, and isolation from the including scope's
variables. It does not rebuild or install plugin assets.

For the source scan, use the PHPCS executable bundled with official Plugin Check:

```powershell
php <wordpress-root>/wp-content/plugins/plugin-check/vendor/bin/phpcs `
  --standard=WordPress `
  --sniffs=WordPress.NamingConventions.PrefixAllGlobals,WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput `
  --runtime-set prefixes vidyalms --ignore-annotations `
  packages/adapter-wordpress/vidyalms/templates
```

This is a targeted scan, with suppressions disabled. It is not a full Plugin
Check run against a release ZIP.

## Source verification, 5 October 2026

- Before: 185 `NonPrefixedVariableFound` and eight `OutputNotEscaped` findings.
- After: zero findings for these three sniffs across all 16 templates.
- All 16 templates pass PHP syntax checks. Nine renders across eight template
  parts pass the output checks on WordPress 7.1.2 / PHP 8.3.33.
- Eight output suppressions were replaced with `esc_attr`, `wp_kses_post`, or a
  nonce-specific `wp_kses` input allowlist. Existing config producers remain
  unchanged; the attribute test verifies that already escaped JSON is not
  double encoded. All template-local variables use the `vidyalms` prefix;
  `$context` remains the read-only input contract used by `Templates::output()`.

The React 18 command and browser steps are in [the UI README](../../ui/README.md#wordpress-react-18-field-regression).
All eight tests pass on React 18.3.1, covering admin and instructor controls.
The original instructor controls fail both behavioral ref tests. Eighteen
focused field, course creation and refused-save tests also pass with the normal
UI runtime. Type checking and lint of the changed files pass. An earlier full UI
run also found two unrelated credential stylesheet failures; the full lint run
reports existing generated-output and unrelated source findings.

## Request-input resolution

The initial **19 findings at 11 read expressions** included a GET read and an
uploaded-file read. ImportPage, WooCommerce, NotificationPages and Templates now
unslash and sanitize at the input reads, while retaining shape validation.

| Source | Final targeted findings | Resolution |
| --- | ---: | --- |
| `src/Content/NotificationPages.php`: `isOneClick`, `postedChannels`, `postedRows` | 0 | Sanitize directly at reads; retain original string/array rejection, channel/category limits, frequency allowlist, order and duplicates. Preference keys are unslashed and sanitized once. |
| `src/Content/Templates.php`: `postValue`, `getValue` | 0 | Guard scalar shape, then unslash and sanitize directly at the POST/GET read. Arrays and objects still return an empty string. |
| `src/Admin/ImportPage.php`: options and upload | 0 with annotations; 1 without | Sanitize options at the read. The single upload-metadata exception in `storeUpload` is described below. |
| `src/Commerce/WooCommerce.php`: product/variation mapping and nonce reads | 0 | Sanitize input at the read; mapping validation and authorization remain in place. |

Run the behavioral checks against a disposable WordPress installation:

```powershell
php packages/adapter-wordpress/tests/request-input-test.php <wordpress-root>
```

All **47 checks pass using real WordPress functions**. The test loads the source
helper classes before the installed plugin can supply older copies. It covers
malformed shapes, scalar coercion, HTML and slashes, exactly one unslash, channel
and frequency limits, preference-key collisions, ordering and duplicates.

Scan all four handlers with official Plugin Check's bundled PHPCS:

```powershell
php <wordpress-root>/wp-content/plugins/plugin-check/vendor/bin/phpcs `
  --standard=WordPress --sniffs=WordPress.Security.ValidatedSanitizedInput `
  packages/adapter-wordpress/vidyalms/src/Content/NotificationPages.php `
  packages/adapter-wordpress/vidyalms/src/Content/Templates.php `
  packages/adapter-wordpress/vidyalms/src/Admin/ImportPage.php `
  packages/adapter-wordpress/vidyalms/src/Commerce/WooCommerce.php
```

The final scan returns **zero errors and warnings**. Repeating it with
`--ignore-annotations` returns exactly one `InputNotSanitized` finding:
`ImportPage.php:1043`, the `$_FILES['import_file']` metadata read in `storeUpload`.
Its one-line exception preserves PHP's temporary filename for upload-specific
validation. The method checks metadata shape, upload error, `is_uploaded_file`,
size and allowed extension, then passes the file through `WpUploadReceiver` and
the import upload policy before private storage. Text-sanitizing this file
metadata would break that contract. There are no input findings in the other
three files even with annotations disabled.

Local evidence: `local-backups/adversarial-input-final-all4.json`,
`local-backups/adversarial-input-final-all4-unsuppressed.json`, and
`local-backups/adversarial-input-handoff.md`. These are targeted source checks;
full release ZIP results are recorded in the security review record linked above.

## Sign-in harness and PHP verification

The sign-in harness now provides `wp_parse_url` and `selected` stubs, models
WordPress escaping without double encoding, and closes its output buffer even
when rendering throws. Reviewed snapshots use the prefixed template variables
and escaped output. Behavioral checks cover query preservation, organisation
filters, mount JSON and buffer cleanup. The notification source snapshot also
reflects the input-read sanitation above.

The focused `SignInGateWordPressTest` run passes **16 tests / 592 assertions**.
Both edited input handlers pass PHPStan; they, the request-input check and the
sign-in harness pass PHP 8.1 and PHP 8.3 syntax checks. All nine real WordPress
template renders still pass, and the 16-template scan remains at zero findings
with annotations disabled.

The final full tooling suite passes **402 tests / 5,841 assertions**, with one
platform-dependent skip and no failures. JUnit evidence is in
`local-backups/adversarial-review/tooling-final.xml`.
