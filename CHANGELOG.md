# Changelog

## [1.1.1] - 2026-04-16

### Fixed
- Removed `sameAs` from JSON-LD Product schema to prevent Google Rich Results duplicate-entity warning. The AlloIA API URL is not a valid `sameAs` target; AI bot discovery is already covered by the existing `link rel="alternate"` and `meta name="ai-content-source"` tags.

## [1.1.0] - 2026-01-28

### Added
- Batch product sync (50 products/batch) to fix Vercel timeout on large catalogs

## [1.0.9] - 2026-01-28

### Added
- Enriched AI product schema with images, brand, GTIN and variations

## [1.0.8]

### Added
- Auto-update notifier (AlloiaUpdater)

## [1.0.0]

### Added
- Initial public release of AlloIA for PrestaShop
