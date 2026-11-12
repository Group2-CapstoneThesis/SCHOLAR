<?php
// handles phinx database migrations
declare(strict_types=1); // keep typed inputs strict

namespace Scholar;

use Phinx\Console\PhinxApplication;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// bridge to Phinx, the library still performs migration/history/seed work
class PhinxCommands {
    public static function run(string $command, array $options = []): void {
        $application = new PhinxApplication();
        $application->setAutoExit(false); // return to our console script when Phinx finishes
        $application->setCatchExceptions(false); // unexpected failures use our private handler

        // ArrayInput receives the same options as a terminal command
        $arguments = [
            'command' => $command,
            '--configuration' => __DIR__ . '/../phinx.php',
            '--environment' => 'development',
            '--no-interaction' => true,
        ];
        foreach ($options as $name => $value) {
            $arguments[$name] = $value; // add specific options, e.g. the legacy adoption target
        }

        $input = new ArrayInput($arguments);
        $output = new BufferedOutput(); // hold output until we know the command succeeded
        $exitCode = $application->run($input, $output);

        // Phinx's status command answers with exit code 3 when some migrations are still "down" (pending)
        // that is a normal answer, not a failure, so print the table and a reminder instead of an error
        if ($command === 'status' && $exitCode === 3) {
            echo $output->fetch();
            echo "Some migrations are pending. Run: php bin/console.php migrate\n";
            return;
        }

        if ($exitCode !== 0) {
            // failed library output can contain sensitive exception details, so do not print it
            throw new RuntimeException('Phinx command failed.');
        }

        echo $output->fetch(); // print the successful migration/status/seed report
    }
}
