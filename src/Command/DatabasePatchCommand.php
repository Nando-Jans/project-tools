<?php

declare(strict_types=1);

namespace NandoJans\ProjectTools\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:database:patch',
    description: 'Imports the latest database dump locally or into another remote environment.',
)]
final class DatabasePatchCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(PATCH_ACC_SSH_HOST)%')]
        private readonly string $accSshHost,
        #[Autowire('%env(PATCH_ACC_SSH_USER)%')]
        private readonly string $accSshUser,
        #[Autowire('%env(PATCH_ACC_REMOTE_DUMP_DIRECTORY)%')]
        private readonly string $accRemoteDumpDirectory,
        #[Autowire('%env(PATCH_PROD_SSH_HOST)%')]
        private readonly string $prodSshHost,
        #[Autowire('%env(PATCH_PROD_SSH_USER)%')]
        private readonly string $prodSshUser,
        #[Autowire('%env(PATCH_PROD_REMOTE_DUMP_DIRECTORY)%')]
        private readonly string $prodRemoteDumpDirectory,
        #[Autowire('%env(DATABASE_HOST)%')]
        private readonly string $databaseHost,
        #[Autowire('%env(DATABASE_NAME)%')]
        private readonly string $databaseName,
        #[Autowire('%env(DATABASE_USER)%')]
        private readonly string $databaseUser,
        #[Autowire('%env(DATABASE_PASSWORD)%')]
        private readonly string $databasePassword,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('environment', InputArgument::REQUIRED, 'Remote environment: acc or prod.')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Destination: local, acc or prod.', 'local')
            ->addOption('target-project-dir', null, InputOption::VALUE_REQUIRED, 'Absolute application directory on the destination server (required for remote targets).')
            ->addOption('non-anon', null, InputOption::VALUE_NONE, 'Download a non-anonymized database dump.')
            ->addOption(
                'document-location',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Copy uploaded documents from REMOTE_PATH to LOCAL_PATH (REMOTE_PATH=LOCAL_PATH). May be repeated.',
            )
            ->addOption('keep', null, InputOption::VALUE_NONE, 'Keep the downloaded dump after importing.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Import locally without asking for confirmation; ignored for remote targets.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $remote = $this->getRemoteConfiguration(strtolower((string) $input->getArgument('environment')));
            $target = strtolower((string) $input->getOption('target'));
            if ($target !== 'local') {
                return $this->patchRemote($input, $io, $target);
            }

            $documentLocations = array_map(
                fn (mixed $location): array => $this->parseDocumentLocation((string) $location),
                $input->getOption('document-location'),
            );
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        if (!$input->getOption('force') && !$io->confirm(sprintf(
            'This will completely overwrite the local database "%s". Continue?',
            $this->databaseName,
        ))) {
            $io->warning('Database patch cancelled.');

            return Command::SUCCESS;
        }

        $localDirectory = $this->projectDir . '/var/backups/remote';

        try {
            $this->createDirectory($localDirectory);
            $remoteFilename = $this->findLatestRemoteDump(
                $remote['host'],
                $remote['user'],
                $remote['directory'],
                (bool) $input->getOption('non-anon'),
            );
            $io->writeln(sprintf('Found remote dump: <info>%s</info>', $remoteFilename));

            $localFilename = $localDirectory . '/' . basename($remoteFilename);
            $this->downloadDump($remote['host'], $remote['user'], $remoteFilename, $localFilename, $io);
            $this->dropDatabase($io);
            $this->importDump($localFilename, $io);
            $this->runMigrations($io);

            foreach ($documentLocations as [$remoteDirectory, $localDocumentDirectory]) {
                $this->copyDocuments(
                    $remote['host'],
                    $remote['user'],
                    $remoteDirectory,
                    $localDocumentDirectory,
                    $io,
                );
            }

            if (!$input->getOption('keep') && is_file($localFilename) && !unlink($localFilename)) {
                throw new \RuntimeException(sprintf('Could not delete downloaded dump "%s".', $localFilename));
            }

            $io->success(sprintf('Database "%s" was patched successfully.', $this->databaseName));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function patchRemote(InputInterface $input, SymfonyStyle $io, string $target): int
    {
        $destination = $this->getRemoteConfiguration($target);
        $source = strtolower((string) $input->getArgument('environment'));
        if ($source === $target) {
            throw new \InvalidArgumentException('Source and destination environments must be different.');
        }

        $projectDirectory = (string) $input->getOption('target-project-dir');
        if (!str_starts_with($projectDirectory, '/') || str_contains($projectDirectory, "\0")) {
            throw new \InvalidArgumentException('Remote targets require an absolute --target-project-dir.');
        }
        if ($input->getOption('document-location') !== []) {
            throw new \InvalidArgumentException('--document-location is only supported for local imports.');
        }
        if (!$input->isInteractive()) {
            $io->error('Remote database copies require interactive confirmation.');

            return Command::INVALID;
        }

        $targetLabel = $target === 'prod' ? 'PRODUCTION' : 'accept';
        $io->warning(sprintf(
            'The latest %s dump from %s will overwrite the database configured in %s on %s (%s).',
            $input->getOption('non-anon') ? 'non-anonymized' : 'anonymized',
            $source,
            $projectDirectory,
            $destination['host'],
            $targetLabel,
        ));
        if (!$io->confirm(sprintf('Copy %s to %s. Are you sure?', $source, $targetLabel), false)
            || ($target === 'prod' && !$io->confirm(
                'Final confirmation: existing PRODUCTION data will be overwritten. Are you absolutely sure?',
                false,
            ))) {
            $io->warning('Database patch cancelled.');

            return Command::SUCCESS;
        }

        // The destination uses its own database credentials and source SSH configuration.
        // Only the local import is forced: the operator has confirmed the remote copy above.
        $arguments = ['php', 'bin/console', 'app:database:patch', $source, '--force', '--no-interaction'];
        foreach (['non-anon', 'keep'] as $option) {
            if ($input->getOption($option)) {
                $arguments[] = '--' . $option;
            }
        }
        $remoteCommand = 'cd ' . escapeshellarg($projectDirectory) . ' && '
            . implode(' ', array_map('escapeshellarg', $arguments));

        try {
            $process = new Process([
                'ssh', '-o', 'BatchMode=yes',
                sprintf('%s@%s', $destination['user'], $destination['host']),
                $remoteCommand,
            ]);
            $process->setTimeout(10800);
            $process->mustRun(static fn (string $type, string $buffer) => $io->write($buffer));
            $io->success(sprintf('Database copy from %s to %s completed.', $source, $targetLabel));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function createDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create directory "%s".', $directory));
        }
    }

    private function findLatestRemoteDump(
        string $sshHost,
        string $sshUser,
        string $remoteDumpDirectory,
        bool $nonAnonymous,
    ): string {
        $type = $nonAnonymous ? 'non-anonymous' : 'anonymous';
        $remoteCommand = sprintf(
            'find %s -maxdepth 1 -type f -name %s -printf %s',
            escapeshellarg($remoteDumpDirectory),
            escapeshellarg(sprintf('*_%s_*.sql.gz', $type)),
            escapeshellarg('%T@ %p\n'),
        );
        $process = new Process(['ssh', sprintf('%s@%s', $sshUser, $sshHost), $remoteCommand]);
        $process->setTimeout(60);
        $process->mustRun();

        $lines = array_values(array_filter(preg_split('/\R/', trim($process->getOutput())) ?: []));

        if ($lines === []) {
            throw new \RuntimeException(sprintf('No %s database dump was found on %s.', $type, $sshHost));
        }

        usort($lines, static function (string $first, string $second): int {
            return (float) strtok($second, ' ') <=> (float) strtok($first, ' ');
        });

        $separatorPosition = strpos($lines[0], ' ');

        if ($separatorPosition === false) {
            throw new \RuntimeException(sprintf('Could not parse remote dump result "%s".', $lines[0]));
        }

        return substr($lines[0], $separatorPosition + 1);
    }

    private function downloadDump(
        string $sshHost,
        string $sshUser,
        string $remoteFilename,
        string $localFilename,
        SymfonyStyle $io,
    ): void {
        $io->section('Downloading database dump');
        $process = new Process([
            'scp',
            sprintf('%s@%s:%s', $sshUser, $sshHost, $remoteFilename),
            $localFilename,
        ]);
        $process->setTimeout(1800);
        $process->mustRun(static fn (string $type, string $buffer) => $io->write($buffer));

        $this->assertValidDump($localFilename);
    }

    private function assertValidDump(string $filename): void
    {
        if (!is_file($filename) || filesize($filename) === 0) {
            throw new \RuntimeException('The downloaded dump file is empty or does not exist.');
        }

        $validationProcess = new Process(['gzip', '-t', $filename]);
        $validationProcess->mustRun();

        $stream = gzopen($filename, 'rb');

        if ($stream === false) {
            throw new \RuntimeException(sprintf('Could not read downloaded dump "%s".', $filename));
        }

        try {
            if (gzread($stream, 1) === '') {
                throw new \RuntimeException('The downloaded database dump contains no SQL.');
            }
        } finally {
            gzclose($stream);
        }
    }

    private function dropDatabase(SymfonyStyle $io): void
    {
        $io->section('Dropping database');
        $process = new Process([
            'mariadb',
            '--host='.$this->databaseHost,
            '--user='.$this->databaseUser,
            '--disable-ssl',
            '-e',
            sprintf('DROP DATABASE `%s`;', $this->databaseName),
        ]);

        $process->setTimeout(3600);

        $process->mustRun(
            static fn (string $type, string $buffer) => $io->write($buffer),
            [
                'MYSQL_PWD' => $this->databasePassword,
            ],
        );

        $io->success('Database dropped');
    }

    private function importDump(string $filename, SymfonyStyle $io): void
    {
        $io->section('Importing database dump');
        $process = new Process(['bash', '-o', 'pipefail', '-c', <<<'SHELL'
            gzip -dc "$1" |
            mariadb \
                --host="$DATABASE_HOST" \
                --user="$DATABASE_USER" \
                --disable-ssl \
                "$DATABASE_NAME"
            SHELL, 'database-patch', $filename], $this->projectDir);
        $process->setTimeout(3600);
        $process->mustRun(
            static fn (string $type, string $buffer) => $io->write($buffer),
            [
                'DATABASE_HOST' => $this->databaseHost,
                'DATABASE_NAME' => $this->databaseName,
                'DATABASE_USER' => $this->databaseUser,
                'MYSQL_PWD' => $this->databasePassword,
            ],
        );
        $io->success('Database imported');
    }

    private function runMigrations(SymfonyStyle $io): void
    {
        $io->section('Running database migrations');
        $process = new Process([
            PHP_BINARY,
            $this->projectDir . '/bin/console',
            'doctrine:migrations:migrate',
            '--no-interaction',
        ], $this->projectDir);
        $process->setTimeout(3600);
        $process->mustRun(static fn (string $type, string $buffer) => $io->write($buffer));
    }

    /** @return array{string, string} */
    private function parseDocumentLocation(string $location): array
    {
        $separatorPosition = strpos($location, '=');

        if ($separatorPosition === false) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid document location "%s". Expected REMOTE_PATH=LOCAL_PATH.',
                $location,
            ));
        }

        $remoteDirectory = rtrim(substr($location, 0, $separatorPosition), '/');
        $localDirectory = rtrim(substr($location, $separatorPosition + 1), '/');

        if ($remoteDirectory === '' || $localDirectory === '') {
            throw new \InvalidArgumentException(sprintf(
                'Invalid document location "%s". Both paths must be non-empty.',
                $location,
            ));
        }

        if (!str_starts_with($localDirectory, '/')) {
            $localDirectory = $this->projectDir . '/' . $localDirectory;
        }

        return [$remoteDirectory, $localDirectory];
    }

    private function copyDocuments(
        string $sshHost,
        string $sshUser,
        string $remoteDirectory,
        string $localDirectory,
        SymfonyStyle $io,
    ): void {
        $io->section(sprintf('Copying uploaded documents to %s', $localDirectory));
        $this->createDirectory($localDirectory);

        $process = new Process([
            'scp',
            '-r',
            sprintf('%s@%s:%s/.', $sshUser, $sshHost, $remoteDirectory),
            $localDirectory,
        ], $this->projectDir);
        $process->setTimeout(3600);
        $process->mustRun(static fn (string $type, string $buffer) => $io->write($buffer));
    }

    /** @return array{host: string, user: string, directory: string} */
    private function getRemoteConfiguration(string $environment): array
    {
        return match ($environment) {
            'acc' => [
                'host' => $this->accSshHost,
                'user' => $this->accSshUser,
                'directory' => $this->accRemoteDumpDirectory,
            ],
            'prod' => [
                'host' => $this->prodSshHost,
                'user' => $this->prodSshUser,
                'directory' => $this->prodRemoteDumpDirectory,
            ],
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown environment "%s". Expected "acc" or "prod".',
                $environment,
            )),
        };
    }
}
