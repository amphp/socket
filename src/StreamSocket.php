<?php

namespace Amp\Socket;

interface StreamSocket extends Socket
{
    /**
     * Raw stream socket resource.
     *
     * @return resource|null
     */
    public function getResource();

    /**
     * @return string|null
     */
    public function getLocalAddress();

    /**
     * @return string|null
     */
    public function getRemoteAddress();
}
