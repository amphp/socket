<?php

namespace Amp\Socket;

use function Amp\Socket\Internal\normalizeBindToOption;

final class BindContext
{
    /** @var string|null */
    private $bindTo;
    /** @var int */
    private $backlog = 128;
    /** @var bool */
    private $reusePort = false;
    /** @var bool */
    private $broadcast = false;
    /** @var bool */
    private $tcpNoDelay = false;
    /** @var int */
    private $chunkSize = 8192;
    /** @var ServerTlsContext|null */
    private $tlsContext;

    public function withoutBindTo(): self
    {
        return $this->withBindTo(null);
    }

    public function withBindTo(?string $bindTo): self
    {
        $bindTo = normalizeBindToOption($bindTo);

        $clone = clone $this;
        $clone->bindTo = $bindTo;

        return $clone;
    }

    public function getBindTo(): ?string
    {
        return $this->bindTo;
    }

    public function getBacklog(): int
    {
        return $this->backlog;
    }

    public function withBacklog(int $backlog): self
    {
        $clone = clone $this;
        $clone->backlog = $backlog;

        return $clone;
    }

    public function hasReusePort(): bool
    {
        return $this->reusePort;
    }

    public function withReusePort(): self
    {
        $clone = clone $this;
        $clone->reusePort = true;

        return $clone;
    }

    public function withoutReusePort(): self
    {
        $clone = clone $this;
        $clone->reusePort = false;

        return $clone;
    }

    public function hasBroadcast(): bool
    {
        return $this->broadcast;
    }

    public function withBroadcast(): self
    {
        $clone = clone $this;
        $clone->broadcast = true;

        return $clone;
    }

    public function withoutBroadcast(): self
    {
        $clone = clone $this;
        $clone->broadcast = false;

        return $clone;
    }

    public function hasTcpNoDelay(): bool
    {
        return $this->tcpNoDelay;
    }

    public function withTcpNoDelay(): self
    {
        $clone = clone $this;
        $clone->tcpNoDelay = true;

        return $clone;
    }

    public function withoutTcpNoDelay(): self
    {
        $clone = clone $this;
        $clone->tcpNoDelay = false;

        return $clone;
    }

    public function getTlsContext(): ?ServerTlsContext
    {
        return $this->tlsContext;
    }

    public function withoutTlsContext(): self
    {
        return $this->withTlsContext(null);
    }

    public function withTlsContext(?ServerTlsContext $tlsContext): self
    {
        $clone = clone $this;
        $clone->tlsContext = $tlsContext;

        return $clone;
    }

    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    public function withChunkSize(int $chunkSize): self
    {
        $clone = clone $this;
        $clone->chunkSize = $chunkSize;

        return $clone;
    }

    public function toStreamContextArray(): array
    {
        $array = ['socket' => [
            'bindto' => $this->bindTo,
            'backlog' => $this->backlog,
            'ipv6_v6only' => true,
            // SO_REUSEADDR has SO_REUSEPORT semantics on Windows
            'so_reuseaddr' => $this->reusePort && \stripos(\PHP_OS, 'WIN') === 0,
            'so_reuseport' => $this->reusePort,
            'so_broadcast' => $this->broadcast,
            'tcp_nodelay' => $this->tcpNoDelay,
        ]];

        if ($this->tlsContext) {
            $array = \array_merge($array, $this->tlsContext->toStreamContextArray());
        }

        return $array;
    }
}
