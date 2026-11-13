# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab PBDB: a taxon of the Paleobiology Database, fossil or living, by its number or name -
  rank, accepted name, common name, extant or not, first and last appearances (intervals and ages in
  Ma), occurrences counted, classification, ecology, reference - a fossil occurrence by its number,
  a search of names, and the fossil occurrences of a place and an age (outside the contract: an age
  is no date), through the PBDB data service 1.2, no key needed.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/pbdb` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
