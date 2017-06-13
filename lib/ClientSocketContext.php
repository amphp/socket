<?php

namespace Amp\Socket;

use function Amp\Socket\Internal\normalizeBindToOption;

final class ClientSocketContext {
    private $bindTo;
    private $connectTimeout = 10000;
    private $maxAttempts = 2;

    public function withBindTo(string $bindTo = null): self {
        $bindTo = normalizeBindToOption($bindTo);

        $clone = clone $this;
        $clone->bindTo = $bindTo;

        return $clone;
    }

    public function getBindTo() /* : ?string */ {
        return $this->bindTo;
    }

    public function withConnectTimeout(int $timeout): self {
        if ($timeout <= 0) {
            throw new \Error("Invalid connect timeout, must be greater than 0, got {$timeout}");
        }

        $clone = clone $this;
        $clone->connectTimeout = $timeout;

        return $clone;
    }

    public function getConnectTimeout(): int {
        return $this->connectTimeout;
    }

    public function withMaxAttempts(int $maxAttempts): self {
        if ($maxAttempts <= 0) {
            throw new \Error("Invalid value, must be greater than 0, got {$maxAttempts}");
        }

        $clone = clone $this;
        $clone->maxAttempts = $maxAttempts;

        return $clone;
    }

    public function getMaxAttempts(): int {
        return $this->maxAttempts;
    }

    public function toStreamContextArray(): array {
        return ["socket" => [
            "bindto" => $this->bindTo,
        ]];
    }
}
