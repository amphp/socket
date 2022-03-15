<?php

namespace Amp\Socket;

final class UnixAddress implements SocketAddress
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function isUnnamed(): bool
    {
        return $this->path === '';
    }

    public function isAbstract(): bool
    {
        return $this->path !== '' && $this->path[0] === "\0";
    }

    public function toString(): string
    {
        return $this->path;
    }

    public function __toString()
    {
        return $this->toString();
    }
}
