<?php

namespace Amp\Socket;

use Amp\Promise;

interface EncryptableSocket extends Socket
{
    public function setupTls(): Promise;

    public function shutdownTls(): Promise;
}
