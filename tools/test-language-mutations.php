<?php

// Targeted fault injection, not an exhaustive mutation score. Each altered
// class is loaded from a temporary bootstrap; source files stay untouched.
$root = dirname(__DIR__);
$cache = $root . '/.phpunit.cache';
if (!is_dir($cache) && !mkdir($cache, 0777, true)) { throw new RuntimeException('Cannot create test cache'); }
$directory = $cache . '/language-mutations-' . bin2hex(random_bytes(6));
if (!mkdir($directory)) { throw new RuntimeException('Cannot create mutation directory'); }
$mutantFile = $directory . '/mutant.php';
$bootstrap = $directory . '/bootstrap.php';
$log = $directory . '/output.txt';

// file, original fragment, broken fragment, expected occurrence count
$mutations = [
    'multiplication precedence' => ['src/Parser/Parser.php', 'TokenType::MULTIPLY->name => 2,', 'TokenType::MULTIPLY->name => 0,', 1],
    'comparison precedence' => ['src/Parser/Parser.php', 'TokenType::LESS_THEN->name => 0,', 'TokenType::LESS_THEN->name => 3,', 1],
    'and/or precedence' => ['src/Parser/Parser.php', 'IfNode::AND_KEYWORD => -1,', 'IfNode::AND_KEYWORD => -3,', 1],
    'duplicate parameters' => ['src/Parser/Parser.php', 'if (isset($argumentNames[$argumentToken->value])) {', 'if (false) {', 1],
    'wrapped header boundary' => ['src/Parser/Parser.php', '$this->peek()?->line <= $headerEnd->line', '$this->peek()?->line <= $header->line', 1],
    'object call versus range delimiter' => ['src/Parser/Parser.php', "array_merge(self::IDENTIFIER_MATH_OPERATORS, ['do', 'to', 'by', 'as'])", 'self::IDENTIFIER_MATH_OPERATORS', 2],
    'outer branch ownership' => ['src/Parser/Parser.php', '$this->getCurrentIndent() !== $ifToken->indent', '$this->getCurrentIndent() < 0', 1],
    'argument order' => ['src/IC/ICParser.php', '$arguments = array_reverse($node->arguments);', '$arguments = $node->arguments;', 3],
    'short circuit choice' => ['src/IC/ICParser.php', '$isOr = $node->operator->value === \'or\';', '$isOr = $node->operator->value === \'and\';', 1],
    'missing-variable negation' => ['src/IC/ICParser.php', 'if ($node->isNot) {', 'if (!$node->isNot) {', 1],
    'procedure arity' => ['src/Runner/Runner.php', 'if ($expectedCount !== $actualCount) {', 'if (false) {', 1],
    'return value' => ['src/Runner/Runner.php', 'if ($returnExpression) {', 'if (false) {', 1],
    'inclusive range endpoint' => ['src/Runner/InternalFunctions.php', '$value <= $end : $value >= $end', '$value < $end : $value > $end', 1],
    'foreach order' => ['src/Runner/InternalFunctions.php', 'return array_values($array);', 'return array_reverse(array_values($array));', 1],
    'record duplicate fields' => ['src/Parser/Parser.php', 'if (isset($seen[$name])) {', 'if (false) {', 1],
    'record evaluation order' => ['src/IC/ICParser.php', 'foreach ($node->values as $value) {', 'foreach (array_reverse($node->values) as $value) {', 1],
    'record field binding' => ['src/Runner/Runner.php', 'peekStack(count($names) - 1 - $i)', 'peekStack($i)', 1],
    'failure handler routing' => ['src/Runner/Runner.php', '$this->process->currentInstructionIndex = $args[1];', '$this->process->currentInstructionIndex = $args[3];', 1],
    'failure propagation' => ['src/Runner/Runner.php', "if (\$frame['handling'] || \$args[2] === '') {", 'if (true) {', 1],
    'next preserves enclosing steps' => ['src/IC/ICParser.php', 'InstructionType::NEXT_LOOP, [$advance, $depth]', 'InstructionType::NEXT_LOOP, [$advance, 0]', 1],
    'handler binding restoration' => ['src/Runner/Runner.php', "\$scope->variables[\$name] = \$frame['bindingValue'];", "\$scope->variables[\$name] = 'broken';", 1],
    'snapshot step frame completeness' => ['src/Serializer/Deserializer.php', "if (array_column(\$scope->steps, 'instruction') !== \$expectedFrames) {", 'if (false) {', 1],
    'next accepts on as a loop name' => ['src/Parser/Parser.php', "(!\$this->matchValue(TokenType::IDENTIFIER, 'on', 1) || \$this->match(TokenType::DOT, 2))", "!\$this->matchValue(TokenType::IDENTIFIER, 'on', 1)", 1],
];

function runChild(array $arguments, string $root, string $log): array
{
    $pipes = [];
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start PHP child process'); }
    fclose($pipes[0]);
    $started = microtime(true);
    do {
        $status = proc_get_status($process);
        if (!$status['running']) { break; }
        if (microtime(true) - $started > 45) {
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('Child exceeded the 45-second wall-clock limit');
        }
        usleep(100000);
    } while (true);
    $exit = $status['exitcode'];
    $closed = proc_close($process);
    if ($exit === -1) { $exit = $closed; }
    return [$exit, file_get_contents($log)];
}

$command = [PHP_BINARY, '-d', 'error_reporting=32767', '-d', 'memory_limit=256M', '-d', 'max_execution_time=40', '-d', 'xdebug.mode=off', $root . '/vendor/bin/phpunit', '--do-not-cache-result', '--no-coverage', '--colors=never', '--stop-on-failure', '--stop-on-error'];
$results = [];
$exitCode = 0;
try {
    [$exit, $output] = runChild($command, $root, $log);
    if ($exit !== 0 || !str_contains($output, 'OK (')) {
        throw new RuntimeException("Unmodified baseline did not pass:\n" . $output);
    }
    echo "Unmodified baseline passed.\n";
    foreach ($mutations as $name => [$path, $search, $replacement, $occurrences]) {
        $source = file_get_contents($root . '/' . $path);
        if (substr_count($source, $search) !== $occurrences) {
            throw new RuntimeException('Mutation needs updating: ' . $name);
        }
        $mutant = str_replace($search, $replacement, $source);
        file_put_contents($mutantFile, $mutant);
        file_put_contents($bootstrap, "<?php\nrequire " . var_export($root . '/vendor/autoload.php', true) . ";\nrequire " . var_export($mutantFile, true) . ";\n");
        [$lintExit, $lintOutput] = runChild([PHP_BINARY, '-l', $mutantFile], $root, $log);
        if ($lintExit !== 0) { throw new RuntimeException('Invalid mutation: ' . $name . "\n" . $lintOutput); }
        [$exit, $output] = runChild(array_merge($command, ['--bootstrap', $bootstrap]), $root, $log);
        $detected = $exit !== 0 && (str_contains($output, 'FAILURES!') || str_contains($output, 'ERRORS!'));
        if (!$detected && $exit !== 0) { throw new RuntimeException('Test infrastructure failed for ' . $name . "\n" . $output); }
        $results[$name] = $detected ? 'detected' : 'SURVIVED';
        echo $results[$name], ': ', $name, "\n";
        if (!$detected) { $exitCode = 1; }
    }
    file_put_contents($cache . '/language-mutation-results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo count(array_filter($results, static fn($result) => $result === 'detected')), '/', count($mutations), " targeted language faults detected.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    // Only remove the exact files created in this invocation's random directory.
    foreach ([$mutantFile, $bootstrap, $log] as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($directory);
}
exit($exitCode);
