# socket

[![Build Status](https://img.shields.io/travis/amphp/socket/master.svg?style=flat-square)](https://travis-ci.org/amphp/socket)
[![CoverageStatus](https://img.shields.io/coveralls/amphp/socket/master.svg?style=flat-square)](https://coveralls.io/github/amphp/socket?branch=master)
![Unstable](https://img.shields.io/badge/api-unstable-orange.svg?style=flat-square)
![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)


`amphp/socket` is a socket lib for establishing and encrypting non-blocking sockets in the [`amp`](https://github.com/amphp/amp)
concurrency framework.

**Required PHP Version**

- PHP 5.5+

**Installation**

```bash
$ composer require amphp/socket: dev-master
```

**Example**

```php
<?php

Amp\run(function () {
    $socket = (yield Amp\Socket\connect('www.google.com:80'));
});
```
