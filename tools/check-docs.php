<?php

// Validate repository documentation without contacting external services.
// Complete examples run as separate PHP programs, never through eval().
use Karboosx\Procer\Procer;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$documents = array_merge([$root . '/readme.md'], glob($root . '/docs/*.md'), [$root . '/syntaxes/INSTALL.md']);
$cache = $root . '/.phpunit.cache';
if (!is_dir($cache) && !mkdir($cache, 0777, true)) {
    throw new RuntimeException('Cannot create documentation check cache');
}
$directory = $cache . '/docs-' . bin2hex(random_bytes(6));
if (!mkdir($directory)) { throw new RuntimeException('Cannot create example directory'); }
$snippet = $directory . '/example.php';
$log = $directory . '/output.txt';
$bootstrap = $directory . '/errors.php';
file_put_contents($bootstrap, <<<'PHP'
<?php
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
PHP);

function runDocumentExample(string $snippet, string $bootstrap, string $log, string $root): array
{
    $command = [PHP_BINARY, '-d', 'error_reporting=32767', '-d', 'display_errors=1',
        '-d', 'memory_limit=128M', '-d', 'max_execution_time=10', '-d', 'xdebug.mode=off',
        '-d', 'auto_prepend_file=' . $bootstrap, $snippet];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]],
        $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start PHP example'); }
    fclose($pipes[0]);
    $start = microtime(true);
    do {
        $status = proc_get_status($process);
        if (!$status['running']) { break; }
        if (microtime(true) - $start > 15) {
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('PHP example exceeded its 15-second wall-clock limit');
        }
        usleep(10000);
    } while (true);
    $closed = proc_close($process);
    return [$status['exitcode'] === -1 ? $closed : $status['exitcode'], file_get_contents($log)];
}

function documentHeadings(string $markdown): array
{
    $markdown = preg_replace('/^```[^\r\n]*\R.*?^```\h*$/ms', '', $markdown);
    preg_match_all('/^#{1,6}\h+(.+?)\h*#*\h*$/m', $markdown, $matches);
    $slugs = [];
    foreach ($matches[1] as $heading) {
        $heading = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $heading);
        $slug = strtolower(preg_replace('/[^\p{L}\p{N}_\-\s]/u', '', $heading));
        $slug = preg_replace('/\s/u', '-', trim($slug));
        $candidate = $slug;
        for ($i = 1; isset($slugs[$candidate]); $i++) { $candidate = $slug . '-' . $i; }
        $slugs[$candidate] = true;
    }
    return $slugs;
}

$counts = ['documents' => 0, 'links' => 0, 'procer' => 0, 'php' => 0];
$errors = [];
$headingCache = [];
try {
    foreach ($documents as $file) {
        $source = str_replace(["\r\n", "\r"], "\n", file_get_contents($file));
        $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
        $counts['documents']++;
        $insideFence = false;
        foreach (explode("\n", $source) as $index => $lineText) {
            if (!str_starts_with($lineText, '```')) { continue; }
            if ($insideFence && trim($lineText) !== '```') {
                $errors[] = $relative . ':' . ($index + 1) . ': closing fence must not have a language tag';
            }
            if (!$insideFence && trim($lineText) === '```') {
                $errors[] = $relative . ':' . ($index + 1) . ': opening fence needs a language tag';
            }
            $insideFence = !$insideFence;
        }
        if ($insideFence) { $errors[] = "$relative: unclosed code fence"; }
        preg_match_all('/(?:<!-- verify:(php|procer)(.*?) -->\n)?^```(\w*)\n(.*?)^```\h*$/ms', $source,
            $blocks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($blocks as $block) {
            $line = 1 + substr_count(substr($source, 0, $block[0][1]), "\n");
            $where = "$relative:$line";
            $marker = $block[1][0];
            $options = trim($block[2][0]);
            $language = $block[3][0];
            $code = $block[4][0];
            try {
                if ($marker !== '' && $marker !== $language) {
                    throw new RuntimeException('Verification marker and code language differ');
                }
                if ($language === 'procer') {
                    if (!in_array($options, ['', 'done'], true)) {
                        throw new RuntimeException('Unknown Procer example mode');
                    }
                    $procer = new Procer();
                    $procer->useDoneKeyword($options === 'done');
                    $procer->printIcCode($code);
                    $counts['procer']++;
                } elseif ($language === 'php' && str_starts_with(ltrim($code), '<?php')) {
                    if ($marker !== 'php') { throw new RuntimeException('Complete PHP example needs verify:php metadata'); }
                    $expected = json_decode($options, true, flags: JSON_THROW_ON_ERROR);
                    if (!is_array($expected) || array_keys($expected) !== ['output'] || !is_string($expected['output'])) {
                        throw new RuntimeException('Expected metadata: {"output":"exact stdout"}');
                    }
                    file_put_contents($snippet, $code);
                    [$exit, $output] = runDocumentExample($snippet, $bootstrap, $log, $root);
                    if ($exit !== 0) { throw new RuntimeException("PHP exited $exit:\n$output"); }
                    if ($output !== $expected['output']) {
                        throw new RuntimeException('Output differs: expected ' . json_encode($expected['output']) . ', got ' . json_encode($output));
                    }
                    $counts['php']++;
                } elseif ($marker !== '') {
                    throw new RuntimeException('PHP verification requires a complete program beginning with <?php');
                }
            } catch (Throwable $error) {
                $errors[] = "$where: " . $error->getMessage();
            }
        }

        // Check repository-relative links and heading anchors, excluding code fences.
        $prose = preg_replace('/^```[^\n]*\n.*?^```\h*$/ms', '', $source);
        preg_match_all('/\[[^\]\n]+\]\(([^)\n]+)\)/', $prose, $links);
        foreach ($links[1] as $link) {
            if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $link)) { continue; }
            [$target, $anchor] = array_pad(explode('#', $link, 2), 2, null);
            $target = rawurldecode(explode('?', $target, 2)[0]);
            $path = $target === '' ? $file : dirname($file) . '/' . $target;
            if (!is_file($path)) {
                $errors[] = "$relative: missing linked file $link";
                continue;
            }
            if ($anchor !== null && $anchor !== '') {
                $resolved = realpath($path);
                $headingCache[$resolved] ??= documentHeadings(file_get_contents($path));
                if (!isset($headingCache[$resolved][rawurldecode($anchor)])) {
                    $errors[] = "$relative: missing heading $link";
                    continue;
                }
            }
            $counts['links']++;
        }
    }
} finally {
    // Delete only the exact temporary files created by this invocation.
    foreach ([$snippet, $log, $bootstrap] as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($directory);
}

foreach ($errors as $error) { fwrite(STDERR, $error . "\n"); }
printf("Checked %d documents: %d local links, %d Procer snippets, %d PHP examples; %d errors.\n",
    $counts['documents'], $counts['links'], $counts['procer'], $counts['php'], count($errors));
exit($errors === [] ? 0 : 1);
