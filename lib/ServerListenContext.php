<?php

namespace Amp\Socket;

use function Amp\Socket\Internal\normalizeBindToOption;

final class ServerListenContext {
    private $bindTo = null;
    private $backlog = 128;
    private $reusePort = false;
    private $broadcast = false;

    public function withBindTo(string $bindTo = null): self {
        $bindTo = normalizeBindToOption($bindTo);

        $clone = clone $this;
        $clone->bindTo = $bindTo;

        return $clone;
    }

    public function getBindTo() {
        return $this->bindTo;
    }

    public function getBacklog() {
        return $this->backlog;
    }

    public function withBacklog(int $backlog): self {
        $clone = clone $this;
        $clone->backlog = $backlog;

        return $clone;
    }

    public function hasReusePort(): bool {
        return $this->reusePort;
    }

    public function withReusePort(): self {
        $clone = clone $this;
        $clone->reusePort = true;

        return $clone;
    }

    public function withoutReusePort(): self {
        $clone = clone $this;
        $clone->reusePort = false;

        return $clone;
    }

    public function hasBroadcast(): bool {
        return $this->broadcast;
    }

    public function withBroadcast(): self {
        $clone = clone $this;
        $clone->broadcast = true;

        return $clone;
    }

    public function withoutBroadcast(): self {
        $clone = clone $this;
        $clone->broadcast = false;

        return $clone;
    }

    public function toStreamContextArray(): array {
        return ["socket" => [
            "bindto" => $this->bindTo,
            "backlog" => $this->backlog,
            "ipv6_v6only" => true,
            "so_reuseport" => $this->reusePort,
            "so_broadcast" => $this->broadcast,
        ]];
    }
}
