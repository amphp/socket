<?php

namespace Amp\Socket;

use Amp\ByteStream\ClosedException;
use Amp\Failure;
use Amp\Promise;

class ServerSocket extends Socket {
    /** @inheritdoc */
    public function enableCrypto(): Promise {
        if (($resource = $this->getResource()) === null) {
            return new Failure(new ClosedException("The socket has been closed"));
        }

        return Internal\enableCrypto($resource, [], true);
    }
}
