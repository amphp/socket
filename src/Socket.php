<?php

namespace Amp\Socket;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\OutputStream;

interface Socket extends InputStream, OutputStream
{
    /**
     * References the read watcher, so the loop keeps running in case there's an active read.
     *
     * @see Loop::reference()
     */
    public function reference();

    /**
     * Unreferences the read watcher, so the loop doesn't keep running even if there are active reads.
     *
     * @see Loop::unreference()
     */
    public function unreference();

    /**
     * Force closes the socket, failing any pending reads or writes.
     */
    public function close();

    /**
     * @return string|null
     */
    public function getLocalAddress();

    /**
     * @return string|null
     */
    public function getRemoteAddress();

    /**
     * Returns the raw stream socket resource or null if the socket is closed or the is no resource to return
     * for the implementation.
     *
     * @return resource|null
     */
    public function getResource();
}
