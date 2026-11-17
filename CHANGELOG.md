# Changelog

All notable changes to this module are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the module uses [Semantic Versioning](https://semver.org/).

For what the module does, see the [user guide](docs/guide/README.md).

## [0.1.0]

Initial version.

### Fixed before the first release
- The order confirmation e-mail sent by the request that places the order now has the custom address lines (the address object of that request
  carries the snapshot rows as soon as they are saved).
- Radio button and checkbox groups have an accessible name (the label of the attribute) in the checkout and in the account forms.
