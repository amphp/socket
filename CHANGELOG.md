##### v0.2.0

- Fax fatal error in 5.6 when no crypto_method assigned in SSL context

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
