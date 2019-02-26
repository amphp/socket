<?php

namespace Amp\Socket;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\OutputStream;
use Amp\Promise;

interface TcpSocket extends Socket, InputStream, OutputStream
{
    /**
     * Enables encryption on this socket.
     *
     * @return Promise
     */
    public function enableCrypto(): Promise;

    /**
     * Disables encryption on this socket.
     *
     * @return Promise
     */
    public function disableCrypto(): Promise;

    /**
     * @return string|null
     */
    public function getRemoteAddress();
}
