<?php

namespace Amp\Socket;

use Amp\Dns\Record;
use function Amp\Socket\Internal\normalizeBindToOption;

final class ConnectContext
{
    private ?string $bindTo = null;

    private float $connectTimeout = 10;
    private int $maxAttempts = 2;
    private float $backoffBase = 2;

    private ?int $typeRestriction = null;

    private bool $tcpNoDelay = false;

    private ?ClientTlsContext $tlsContext = null;

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

    public function withConnectTimeout(float $timeout): self
    {
        if ($timeout <= 0) {
            throw new \ValueError("Invalid connect timeout ({$timeout}), must be greater than 0");
        }

        $clone = clone $this;
        $clone->connectTimeout = $timeout;

        return $clone;
    }

    public function getConnectTimeout(): float
    {
        return $this->connectTimeout;
    }

    public function withMaxAttempts(int $maxAttempts): self
    {
        if ($maxAttempts <= 0) {
            throw new \ValueError("Invalid max attempts ({$maxAttempts}), must be greater than 0");
        }

        $clone = clone $this;
        $clone->maxAttempts = $maxAttempts;

        return $clone;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function withExponentialBackoffBase(float $base): self
    {
        if ($base < 0) {
            throw new \ValueError("Invalid exponential backoff base ({$base}), must be greater than or equal to 0");
        }

        $clone = clone $this;
        $clone->backoffBase = $base;

        return $clone;
    }

    public function getExponentialBackoffBase(): float
    {
        return $this->backoffBase;
    }

    public function withoutDnsTypeRestriction(): self
    {
        return $this->withDnsTypeRestriction(null);
    }

    public function withDnsTypeRestriction(?int $type): self
    {
        if ($type !== null && $type !== Record::AAAA && $type !== Record::A) {
            throw new \ValueError('Invalid resolver type restriction');
        }

        $clone = clone $this;
        $clone->typeRestriction = $type;

        return $clone;
    }

    public function getDnsTypeRestriction(): ?int
    {
        return $this->typeRestriction;
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

    public function withoutTlsContext(): self
    {
        return $this->withTlsContext(null);
    }

    public function withTlsContext(?ClientTlsContext $tlsContext): self
    {
        $clone = clone $this;
        $clone->tlsContext = $tlsContext;

        return $clone;
    }

    public function getTlsContext(): ?ClientTlsContext
    {
        return $this->tlsContext;
    }

    public function toStreamContextArray(): array
    {
        $options = [
            'tcp_nodelay' => $this->tcpNoDelay,
        ];

        if ($this->bindTo !== null) {
            $options['bindto'] = $this->bindTo;
        }

        $array = ['socket' => $options];

        if ($this->tlsContext) {
            $array = \array_merge($array, $this->tlsContext->toStreamContextArray());
        }

        return $array;
    }
}
