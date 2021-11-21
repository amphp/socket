<?php

namespace Amp\Socket;

use Amp\ByteStream\ClosableStream;
use Amp\ByteStream\InputStream;
use Amp\ByteStream\OutputStream;
use Amp\ByteStream\ReferencedStream;

interface Socket extends InputStream, OutputStream, ClosableStream, ReferencedStream
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
