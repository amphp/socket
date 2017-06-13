<?php

namespace Amp\Socket;

use function Amp\Socket\Internal\normalizeBindToOption;

final class ClientConnectContext {
    private $bindTo = null;
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
            throw new \Error("Invalid connect timeout ({$timeout}), must be greater than 0");
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
            throw new \Error("Invalid max attempts ({$maxAttempts}), must be greater than 0");
        }

        $clone = clone $this;
        $clone->maxAttempts = $maxAttempts;

        return $clone;
    }

    public function getMaxAttempts(): int {
        return $this->maxAttempts;
    }

    public function toStreamContextArray(): array {
        $options = [];

        if ($this->bindTo !== null) {
            $options["bindto"] = $this->bindTo;
        }

        return ["socket" => $options];
    }
}
