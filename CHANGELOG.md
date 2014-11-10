#### v0.4.5

- Improved error message for easier debugging

#### v0.4.4

- Use explicit reactor reference in futures

#### v0.4.3

- ???

#### v0.4.2

- ???

#### v0.4.1

- ???

v0.4.0
======

- Migrate to amphp/amp for event reactor and promises
- Migrate DaveRandom/Addr dependency to amphp/dns

v0.3.0
======

- Package CA certs with distribution

##### v0.2.8

- Fix Issue #1: Bad param for Failure constructor

##### v0.2.7

- Fix fatal error in 5.6 when no crypto_method assigned in SSL context

##### v0.2.6

- Fix fatal error when no existing SSL context assigned on stream

##### v0.2.5

- @TODO What was fixed here?

##### v0.2.4

- Fix naming error, correctly discern ssl context match

##### v0.2.3

- Fix some outdated names, update README, etc.

##### v0.2.2

- Don't explicitly trust OS provided CAs in 5.6+

##### v0.2.1

- Fix cipher variable typo failing all encryption attempts


v0.2.0
======

- Add secure default ciphers for PHP < 5.6

> **BC BREAKS:**

- Removed `Encryptor::DEFAULT_CAFILE` constant (unnecessary)

v0.1.0
======

Initial versioned release
