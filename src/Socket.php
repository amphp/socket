<?php

namespace Amp\Socket;

interface Socket
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
}
