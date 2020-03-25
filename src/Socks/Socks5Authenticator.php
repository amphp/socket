<?php

namespace Amp\Socket\Socks;

interface Socks5Authenticator
{
    public function getIdentifier(): int;
}
