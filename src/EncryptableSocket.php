<?php

namespace Amp\Socket;

use Amp\Promise;

interface EncryptableSocket extends Socket
{
    public function enableCrypto(): Promise;

    public function disableCrypto(): Promise;
}
