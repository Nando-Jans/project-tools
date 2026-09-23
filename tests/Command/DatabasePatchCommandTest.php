<?php

declare(strict_types=1);

use NandoJans\ProjectTools\Command\DatabasePatchCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DatabasePatchCommandTest extends TestCase
{
    private string $directory;
    private string $oldPath;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/database-patch-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/ssh', <<<'SH'
#!/bin/sh
printf '%s\n' "$@" > "$PATCH_TEST_LOG"
exit "${PATCH_TEST_EXIT:-0}"
SH
        );
        chmod($this->directory . '/ssh', 0700);
        $this->oldPath = (string) getenv('PATH');
        putenv('PATH=' . $this->directory . ':' . $this->oldPath);
        $_ENV['PATCH_TEST_LOG'] = $this->directory . '/calls';
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->oldPath);
        unset($_ENV['PATCH_TEST_LOG'], $_ENV['PATCH_TEST_EXIT']);
        putenv('PATCH_TEST_EXIT');
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public static function confirmations(): iterable
    {
        yield 'prod to acc' => ['prod', 'acc', ['yes'], 1, true];
        yield 'acc to prod' => ['acc', 'prod', ['yes', 'yes'], 2, true];
        yield 'decline acc' => ['prod', 'acc', ['no'], 1, false];
        yield 'decline first prod' => ['acc', 'prod', ['no'], 1, false];
        yield 'decline second prod' => ['acc', 'prod', ['yes', 'no'], 2, false];
        yield 'default no' => ['acc', 'prod', [''], 1, false];
        yield 'second default no' => ['acc', 'prod', ['yes', ''], 2, false];
    }

    #[DataProvider('confirmations')]
    public function testConfirmations(string $source, string $target, array $answers, int $prompts, bool $runs): void
    {
        $tester = $this->tester();
        $tester->setInputs($answers);
        self::assertSame(0, $tester->execute([
            'environment' => $source, '--target' => $target,
            '--target-project-dir' => "/srv/app with 'quote",
            '--force' => true, '--non-anon' => true, '--keep' => true,
        ]));
        self::assertSame($prompts, substr_count($tester->getDisplay(), '(yes/no)'));
        self::assertSame($runs, is_file($this->directory . '/calls'), $tester->getDisplay());
        if ($runs) {
            $call = file_get_contents($this->directory . '/calls');
            self::assertStringContainsString($target . '-user@' . $target . '-host', $call);
            self::assertStringContainsString(escapeshellarg("/srv/app with 'quote"), $call);
            self::assertStringContainsString("'app:database:patch' '" . $source . "'", $call);
            self::assertStringContainsString("'--non-anon' '--keep'", $call);
        }
    }

    public function testNonInteractiveCannotBypassConfirmations(): void
    {
        $tester = $this->tester();
        self::assertSame(2, $tester->execute([
            'environment' => 'acc', '--target' => 'prod',
            '--target-project-dir' => '/srv/app', '--force' => true,
        ], ['interactive' => false]));
        self::assertFileDoesNotExist($this->directory . '/calls');
    }

    public static function invalidOptions(): iterable
    {
        yield [['--target' => 'unknown']];
        yield [['--target' => 'acc']];
        yield [['--target' => 'prod', '--target-project-dir' => 'relative']];
        yield [['--target' => 'prod', '--document-location' => ['a=b']]];
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsDoNotStartSsh(array $options): void
    {
        self::assertSame(2, $this->tester()->execute($options + [
            'environment' => 'acc', '--target-project-dir' => '/srv/app',
        ]));
        self::assertFileDoesNotExist($this->directory . '/calls');
    }

    public function testSshFailureIsReported(): void
    {
        $_ENV['PATCH_TEST_EXIT'] = '1';
        $tester = $this->tester();
        $tester->setInputs(['yes']);
        self::assertSame(1, $tester->execute([
            'environment' => 'prod', '--target' => 'acc', '--target-project-dir' => '/srv/app',
        ]));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new DatabasePatchCommand(
            $this->directory, 'acc-host', 'acc-user', '/acc/dumps',
            'prod-host', 'prod-user', '/prod/dumps', 'localhost', 'local_db', 'user', 'password',
        ));
    }
}
