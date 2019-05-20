<?php

namespace Amp\Socket;

use Amp\Promise;

interface EncryptableServerSocket extends Socket
{
    public function setupTls(): Promise;

    public function shutdownTls(): Promise;
}
