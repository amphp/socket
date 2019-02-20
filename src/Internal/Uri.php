<?php

namespace Amp\Socket\Internal;

use League\Uri\AbstractUri;

final class Uri extends AbstractUri
{
    protected static $supported_schemes = [
        'tcp' => null,
        'udp' => null,
        'unix' => null,
        'udg' => null,
    ];

    protected function isValidUri(): bool
    {
        if ($this->scheme === null || !\array_key_exists($this->scheme, self::$supported_schemes)) {
            return false;
        }

        if (($this->fragment !== null && $this->fragment !== '') || ($this->query !== null && $this->query !== '')) {
            return false;
        }

        if ($this->scheme === 'unix') {
            return $this->path !== '' && $this->host === '' && $this->port === null;
        }

        return $this->host !== '' && $this->port !== null && $this->port !== 0 && $this->path === '';
    }
}
