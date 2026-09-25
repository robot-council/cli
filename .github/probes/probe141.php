<?php
// cli#141: does stream_select() honor its timeout on a quiet pipe on POSIX?
// Mirrors the pre-#138 bridge loop (stream_set_blocking(false) + @stream_select with a timeout),
// with a 1-second tick instead of 5 so the run is short. Counts wakes that carried no data.
// Child mode: select on the given stream for N seconds.
if (($argv[1] ?? '') === 'child') {
    $seconds = (int) $argv[2];
    $in = STDIN;
    if (($argv[3] ?? '') === 'socket') {
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $in = $a; // $b held open, never written
    }
    stream_set_blocking($in, false);
    $start = microtime(true); $wakes = 0; $data = 0;
    while (microtime(true) - $start < $seconds) {
        $r = [$in]; $w = null; $e = null;
        $ready = @stream_select($r, $w, $e, 1);
        if ($ready === 0) { $wakes++; } elseif ($ready > 0) { $data++; if (fread($in, 65536) === '' && feof($in)) break; }
    }
    echo json_encode(['stream' => $argv[3] ?? 'pipe', 'meta' => stream_get_meta_data($in)['stream_type'], 'fifo' => ((fstat($in)['mode'] ?? 0) & 0170000) === 0010000, 'socket_mode' => ((fstat($in)['mode'] ?? 0) & 0170000) === 0140000, 'seconds' => round(microtime(true) - $start, 2), 'empty_wakes' => $wakes, 'data_wakes' => $data]), "\n";
    exit(0);
}
$seconds = (int) ($argv[1] ?? 10);
echo json_encode(['os' => PHP_OS, 'uname' => php_uname('s').' '.php_uname('r').' '.php_uname('m'), 'php' => PHP_VERSION]), "\n";
foreach (['pipe', 'socket'] as $kind) {
    // stdin is an anonymous pipe(2) that the parent holds open and never writes
    $p = proc_open([PHP_BINARY, __FILE__, 'child', (string) $seconds, $kind], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[0]); fclose($pipes[1]); proc_close($p);
    echo trim($out), "\n";
}
