<?php

declare(strict_types=1);

namespace NandoJans\ProjectTools\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

#[AsCommand(
    name: 'app:database:dump',
    description: 'Creates a compressed database dump.',
)]
#[AsCronTask(expression: '0 3 * * *', timezone: 'Europe/Amsterdam')]
final class DatabaseDumpCommand extends Command
{
    public function __construct(
        private readonly LockFactory $lockFactory,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(DATABASE_NAME)%')]
        private readonly string $databaseName,
        #[Autowire('%env(DATABASE_USER)%')]
        private readonly string $databaseUser,
        #[Autowire('%env(DATABASE_PASSWORD)%')]
        private readonly string $databasePassword,
        #[Autowire('%env(DATABASE_HOST)%')]
        private readonly string $databaseHost,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('non-anon', null, InputOption::VALUE_NONE, 'Create a non-anonymized dump.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = $this->lockFactory->createLock('database-dump', 3600);

        if (!$lock->acquire()) {
            $io->warning('A database dump is already being generated.');

            return Command::SUCCESS;
        }

        try {
            $dumpDirectory = $this->projectDir . '/var/backups/database';
            $this->createDirectory($dumpDirectory);

            $type = $input->getOption('non-anon') ? 'non-anonymous' : 'anonymous';
            $timestamp = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Amsterdam'));
            $filename = sprintf(
                '%s/%s_%s_%s.sql.gz',
                $dumpDirectory,
                $this->databaseName,
                $type,
                $timestamp->format('Y-m-d_H-i-s'),
            );

            $process = new Process(['bash', '-o', 'pipefail', '-c', <<<'SHELL'
                mariadb-dump \
                    --host="$DATABASE_HOST" \
                    --user="$DATABASE_USER" \
                    --single-transaction \
                    --skip-ssl \
                    --quick \
                    --routines \
                    --triggers \
                    --events \
                    --default-character-set=utf8mb4 \
                    "$DATABASE_NAME" \
                | gzip > "$1"
                SHELL, 'database-dump', $filename]);
            $process->setTimeout(3600);
            $process->mustRun(null, [
                'DATABASE_HOST' => $this->databaseHost,
                'DATABASE_USER' => $this->databaseUser,
                'DATABASE_NAME' => $this->databaseName,
                'DUMP_FILE' => $filename,
                'MYSQL_PWD' => $this->databasePassword,
            ]);

            $this->assertValidDump($filename);

            $this->removeOldDumps($dumpDirectory, 14);
            $this->removeExpiredDumps($dumpDirectory, 30);

            $io->success(sprintf(
                'Database dump created: %s (%s)',
                $filename,
                $this->formatBytes((int) filesize($filename)),
            ));

            return Command::SUCCESS;
        } catch (ProcessFailedException $exception) {
            if (isset($filename) && is_file($filename)) {
                unlink($filename);
            }

            $io->error(['Generating the database dump failed.', $exception->getProcess()->getErrorOutput()]);

            return Command::FAILURE;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function assertValidDump(string $filename): void
    {
        if (!is_file($filename) || filesize($filename) === 0) {
            throw new \RuntimeException('The dump process completed without creating a valid dump file.');
        }

        $process = new Process(['gzip', '-t', $filename]);
        $process->mustRun();

        $stream = gzopen($filename, 'rb');

        if ($stream === false) {
            throw new \RuntimeException(sprintf('Could not read database dump "%s".', $filename));
        }

        try {
            if (gzread($stream, 1) === '') {
                throw new \RuntimeException('The dump process created an empty database dump.');
            }
        } finally {
            gzclose($stream);
        }
    }

    private function createDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create dump directory "%s".', $directory));
        }
    }

    private function removeOldDumps(string $directory, int $keep): void
    {
        $files = glob($directory . '/*.sql.gz');

        if ($files === false || count($files) <= $keep) {
            return;
        }

        usort($files, static fn (string $first, string $second): int => filemtime($second) <=> filemtime($first));

        foreach (array_slice($files, $keep) as $file) {
            $this->deleteDump($file, 'old');
        }
    }

    private function removeExpiredDumps(string $directory, int $retentionDays): void
    {
        $files = glob($directory . '/*.sql.gz');

        if ($files === false) {
            return;
        }

        $expiresBefore = time() - ($retentionDays * 86400);

        foreach ($files as $file) {
            $modifiedAt = filemtime($file);

            if ($modifiedAt !== false && $modifiedAt < $expiresBefore) {
                $this->deleteDump($file, 'expired');
            }
        }
    }

    private function deleteDump(string $file, string $reason): void
    {
        if (!unlink($file)) {
            throw new \RuntimeException(sprintf('Could not delete %s database dump "%s".', $reason, $file));
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return sprintf('%.2f %s', $value, $units[$unit]);
    }
}
