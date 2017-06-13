<?php

namespace Amp\Socket;

use function Amp\Socket\Internal\normalizeBindToOption;

final class ServerSocketContext {
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

    public function getBindTo() /* : ?string */ {
        return $this->bindTo;
    }

    public function getBacklog() /* : ?int */ {
        return $this->backlog;
    }

    public function withBacklog(int $backlog): self {
        $clone = clone $this;
        $clone->backlog = $backlog;

        return $clone;
    }

    public function isReusePort(): bool {
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

    public function isBroadcast(): bool {
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
