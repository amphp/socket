<?php

namespace Amp\Socket;

enum TlsState
{
    case Disabled;
    case SetupPending;
    case Enabled;
    case ShutdownPending;
}
