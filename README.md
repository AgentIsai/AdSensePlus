# GoogleAdSense (MediaWiki extension)

Adds configurable Google AdSense ad placements to MediaWiki pages (tested design targets: Vector, Vector 2022, MonoBook; with a universal DOM injector intended to work across many skins, plus Cosmos-focused selectors).

> **Important**: This extension only provides technical placement. You are responsible for complying with Google AdSense program policies and applicable privacy laws.

## Install

1. Copy this directory to `extensions/GoogleAdSense/`
2. Add to `LocalSettings.php`:

```php
wfLoadExtension( 'GoogleAdSense' );

// Enable ads site-wide
$wgGoogleAdSenseEnabled = true;

// Required: your AdSense client id
$wgGoogleAdSenseClient = 'ca-pub-1234567890123456';

// Slots per placement (strings; required per enabled placement)
$wgGoogleAdSenseSlots = [
  'inline' => '1111111111',
  'sidebar' => '2222222222',
  'banner' => '3333333333',
  'afterContent' => '4444444444',
  'footer' => '5555555555',
  'floatingBottom' => '6666666666',
  'linkClick' => '7777777777',
];

// Toggle placements
$wgGoogleAdSensePlacements = [
  'inline' => true,
  'sidebar' => true,
  'banner' => true,
  'afterContent' => true,
  'footer' => true,
  'floatingBottom' => true,
  'linkClick' => false,
];

// Restrict to namespaces (defaults to NS_MAIN=0). Exemptions (logged-in / rights) override this.
$wgGoogleAdSenseNamespaces = [ NS_MAIN, NS_HELP ];

// Default behavior: hide ads for logged-in users
$wgGoogleAdSenseExemptLoggedIn = true;

// Universal injector (JS-based DOM placement) for broader skin support.
$wgGoogleAdSenseUniversalInjector = true;
```

## Rights

This extension defines two rights:

- `googleadsense-viewads`: Permission to see ads.
- `googleadsense-exempt`: Always exempt from ads.

**Default behavior (from extension.json):**
- Anonymous users (`*`) are granted `googleadsense-viewads`.
- Registered users (`user`) have `googleadsense-viewads` revoked.
- Additionally, `$wgGoogleAdSenseExemptLoggedIn = true` hides ads for logged-in users even if you grant them `googleadsense-viewads`.

To show ads to logged-in users, do BOTH:
```php
$wgGoogleAdSenseExemptLoggedIn = false;
$wgGroupPermissions['user']['googleadsense-viewads'] = true;
```

To exempt a group:
```php
$wgGroupPermissions['sysop']['googleadsense-exempt'] = true;
```

## Inline ads

Use the parser tag:

```wikitext
<adsense />
<adsense slot="1111111111" />
```

Inline ads are inserted **after parser cache** so that they can respect per-request eligibility (logged-in exemptions / rights).

## Safety

- Ads are not rendered on pages where `OutputPage::disallowUserJs()` has been used (e.g., login-related pages).
- Ads are wrapped in `.noprint` to avoid appearing in print views.
