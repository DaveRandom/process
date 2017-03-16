<?php

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Process\StreamedProcess;

Amp\Loop::run(function() {
    $process = new StreamedProcess("echo 1; sleep 1; echo 2; sleep 1; echo 3; exit 42");
    $promise = $process->execute();

    $stdout = $process->getStdout();

    while (yield $stdout->advance()) {
        echo $stdout->getCurrent();
    }

    $code = yield $promise;
    echo "Process exited with {$code}.\n";
});
