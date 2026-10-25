<?php

it('agrees with a second implementation, written from the RFCs alone, on every digest, root, manifest and proof the standard publishes', function () {
    exec('python3 -I -B '.escapeshellarg(__DIR__.'/independent/check.py').' '.escapeshellarg(standardFile('')).' 2>&1', $output, $status);

    expect($status)->toBe(0, implode("\n", $output))
        ->and(end($output))->toEndWith('checks agree');
});

it('runs the second implementation\'s own tests of RFC 8785 and RFC 9162', function () {
    exec('python3 -I -B '.escapeshellarg(__DIR__.'/independent/test_jcs.py').' 2>&1', $output, $status);

    expect($status)->toBe(0, implode("\n", $output));
});
