<?php

namespace Amp\Socket;

use Amp\Promise;

interface StreamSocket extends Socket {
    /**
     * Raw stream socket resource.
     *
     * @return resource|null
     */
    public function getResource();

    /**
     * Enables encryption on this socket.
     *
     * @return Promise
     */
    public function enableCrypto(): Promise;

    /**
     * @return string|null
     */
    public function getLocalAddress();

    /**
     * @return string|null
     */
    public function getRemoteAddress();
}