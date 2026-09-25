<?php
// public/workers/run.php
// =============================================================
// Master runner. Two modes:
//
//   CLI (interactive):  php public/workers/run.php
//   CLI (direct):       php public/workers/run.php ai_health
//   CLI (all):          php public/workers/run.php all
//   CLI (list):         php public/workers/run.php --list
//
//   HTTP:  /public/workers/run.php?task=ai_health&token=AI_CRON_TOKEN

require_once __DIR__ . '/../../bootstrap.php';

$isCli = (PHP_SAPI === 'cli');

// ---- HTTP mode: token check, then run the requested task ----
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $expected = env('AI_CRON_TOKEN', '');
    $given    = $_GET['token'] ?? '';
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }

    $task = $_GET['task'] ?? 'all';
    dispatch($task, true);
    exit;
}

// ---- CLI mode: parse args or show the interactive menu ----

$registry = new Registry(
    __DIR__ . '/../../config/workers.php',
    __DIR__ . '/../..'
);

$task = $argv[1] ?? null;

// --list: just print available workers and exit
if ($task === '--list' || $task === '-l') {
    printWorkerList($registry);
    exit(0);
}

// No args: interactive menu
if ($task === null) {
    $task = promptForTask($registry);
    if ($task === null) {
        Output::dim('Cancelled.');
        exit(0);
    }
}

// Normal dispatch.
dispatch($task, false, $registry);
exit(0);

// ============================================================
// Helpers
// ============================================================

function dispatch(string $task, bool $httpMode, ?Registry $registry = null): void
{
    if ($registry === null) {
        $registry = new Registry(
            __DIR__ . '/../../config/workers.php',
            __DIR__ . '/../..'
        );
    }

    if ($task === 'all') {
        runAll($registry);
        return;
    }

    if (!$registry->has($task)) {
        Output::fail("Unknown worker: {$task}");
        printWorkerList($registry);
        if (!$httpMode) exit(1);
        return;
    }

    runOne($task, $registry->path($task));
}

function runOne(string $name, string $path): void
{
    // Each worker file is a standalone script that ends with `exit(...)`.
    // We include it — its own boot code runs and exits when done.
    require $path;
}

function runAll(Registry $registry): void
{
    $workers = $registry->all();
    $total = count($workers);
    $current = 0;
    $startTime = microtime(true);

    Output::line();
    Output::bold("Running all {$total} workers...");

    foreach ($workers as $name => $path) {
        $current++;
        Output::line();
        Output::dim("  [{$current}/{$total}] " . $name);
        // Worker includes exit() on success so we shell out instead.
        if (PHP_OS_FAMILY === 'Windows') {
            system('php ' . escapeshellarg($path), $code);
        } else {
            system('php ' . escapeshellarg($path), $code);
        }
        if ($code !== 0) {
            Output::fail('Worker exited with code ' . $code);
        }
    }

    $elapsed = microtime(true) - $startTime;
    Output::line();
    Output::ok('All workers finished in ' . number_format($elapsed, 2) . 's');
}

function printWorkerList(Registry $registry): void
{
    Output::line();
    Output::bold('Available workers:');
    Output::line();
    foreach ($registry->all() as $name => $path) {
        Output::line('    ' . str_pad($name, 16) . basename($path));
    }
    Output::line();
    Output::dim('  Run one:  php public/workers/run.php <name>');
    Output::dim('  Run all:  php public/workers/run.php all');
    Output::line();
}

function promptForTask(Registry $registry): ?string
{
    Output::line();
    Output::bold('  Cafe Side — Worker Runner');
    Output::line();

    $workers = $registry->all();
    $i = 1;
    $map = [];
    foreach ($workers as $name => $path) {
        echo '    ' . str_pad((string)$i, 3) . str_pad($name, 16) . PHP_EOL;
        $map[(string)$i] = $name;
        $i++;
    }
    echo '    ' . str_pad('a', 3) . str_pad('all', 16) . '(run everything)' . PHP_EOL;
    echo '    ' . str_pad('q', 3) . 'quit' . PHP_EOL;
    Output::line();

    Output::line('  Choose a worker: ');
    $input = trim((string) fgets(STDIN));

    if ($input === 'q') return null;
    if ($input === 'a') return 'all';
    if (isset($map[$input])) return $map[$input];
    if (isset($workers[$input])) return $input;

    Output::fail('Invalid choice.');
    return null;
}