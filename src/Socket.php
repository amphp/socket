<?php

namespace Amp\Socket;

use Amp\ByteStream\ClosableStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\ByteStream\ReferencedStream;

interface Socket extends ReadableStream, WritableStream, ClosableStream, ReferencedStream
{
    /**
     * @return SocketAddress
     */
    public function getLocalAddress(): SocketAddress;

    /**
     * @return SocketAddress
     */
    public function getRemoteAddress(): SocketAddress;
}
