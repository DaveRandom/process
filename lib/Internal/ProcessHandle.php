<?php

namespace Amp\Process\Internal;

use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Process\ProcessInputStream;

abstract class ProcessHandle
{
    /** @var resource */
    public $stdin;

    /** @var resource */
    public $stdout;

    /** @var resource */
    public $stderr;

    /** @var int */
    public $pid = 0;

    /** @var bool */
    public $status = ProcessStatus::STARTING;
}
