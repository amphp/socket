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
}
