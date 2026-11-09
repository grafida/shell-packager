<?php

// A minimal shell worker for the packager's tests (protocol v1, grafida/shell protocol/v1.md).
//
//   worker.php --hello   prints what it can see (the PHAR, its ini, its dependencies) and exits
//   worker.php           sends `ready`, then answers every request: `/` is a page that reports its
//                        origin to `/report` (written to stderr, i.e. the shell's log) and asks the
//                        shell to quit; that is the end-to-end test of a packaged tarball.
require __DIR__ . '/../vendor/autoload.php';

$hello = (new App\Greeter())->hello();

if (($argv[1] ?? '') === '--hello') {
    echo $hello, ' ', ini_get('memory_limit'), ' ', Test\RuntimeLib\Lib::NAME, ' ',
        class_exists(Test\DevLib\DevLib::class) ? 'dev-lib-present' : 'no-dev-lib', ' ',
        file_get_contents(__DIR__ . '/../assets/index.html');
    exit(0);
}

function frame(array $header, string $body = ''): void
{
    $json = json_encode($header, JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, 'GSF1' . pack('V', strlen($json)) . $json . pack('P', strlen($body)) . $body);
    fflush(STDOUT);
}

function exactly(int $length): ?string
{
    $data = '';

    while (strlen($data) < $length) {
        $chunk = fread(STDIN, $length - strlen($data));

        if ($chunk === false || $chunk === '') {
            return null;
        }

        $data .= $chunk;
    }

    return $data;
}

fwrite(STDERR, 'fixture: ' . $hello . ', memory_limit=' . ini_get('memory_limit') . "\n");
frame(['type' => 'ready', 'protocol' => 1, 'pid' => getmypid(), 'app' => 'fixture', 'version' => '0']);

$page = <<<'HTML'
    <!doctype html><meta charset="utf-8"><title>fixture</title>
    <script>
    addEventListener('load', async () => {
      await fetch('/report?origin=' + encodeURIComponent(location.origin));
      fetch('/_native/quit', {method: 'POST', body: '{}'});
    });
    </script>
    HTML;

while (($magic = exactly(4)) === 'GSF1') {
    $header = json_decode((string) exactly(unpack('V', (string) exactly(4))[1]), true);
    $length = unpack('P', (string) exactly(8))[1];
    $length > 0 && exactly($length);

    if (($header['type'] ?? '') !== 'request') {
        break;
    }

    $url = parse_url($header['url']);

    if (($url['path'] ?? '/') === '/report') {
        parse_str($url['query'] ?? '', $query);
        fwrite(STDERR, 'fixture-report: origin=' . ($query['origin'] ?? '?') . "\n");
        frame(['type' => 'response', 'id' => $header['id'], 'status' => 204, 'headers' => (object) []]);

        continue;
    }

    frame(['type' => 'response', 'id' => $header['id'], 'status' => 200, 'headers' => ['content-type' => ['text/html; charset=utf-8']]], $page);
}
