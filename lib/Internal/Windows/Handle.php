<?php declare(strict_types=1);

namespace Amp\Process\Internal\Windows;

use Amp\Deferred;
use Amp\Process\Internal\ProcessHandle;

final class Handle extends ProcessHandle
{
    /** @var Deferred */
    public $startDeferred;

    /** @var resource */
    public $proc;

    /** @var int */
    public $wrapperPid;

    /** @var resource */
    public $wrapperStderrPipe;

    /** @var resource[] */
    public $sockets;

    /** @var string */
    public $connectTimeoutWatcher;

    /** @var string[] */
    public $securityTokens;
}
