<?php declare(strict_types=1);

namespace Amp\Socket;

final class UnixAddress implements SocketAddress
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function getType(): SocketAddressType
    {
        return SocketAddressType::Unix;
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

    /**
     * @see toString
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
