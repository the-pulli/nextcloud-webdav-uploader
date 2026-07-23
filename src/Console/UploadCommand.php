<?php

declare(strict_types=1);

namespace Pulli\NextcloudWebdavUploader\Console;

use Pulli\NextcloudWebdavUploader\Exceptions\NextcloudException;
use Pulli\NextcloudWebdavUploader\NextcloudClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

use function array_column;
use function array_filter;
use function array_map;
use function array_unique;
use function basename;
use function count;
use function file_exists;
use function filesize;
use function is_file;
use function realpath;
use function rtrim;
use function sprintf;
use function trim;

final class UploadCommand extends Command
{
    public function __construct(private ?NextcloudClient $client = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('upload')
            ->setDescription('Uploads file(s) and/or whole folder(s) to Nextcloud via WebDAV, chunking automatically above the single-PUT size limit')
            ->addArgument('folder', InputArgument::OPTIONAL, 'Target folder in Nextcloud (relative to the user files root; created if missing)', 'Uploads')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Local file or directory to upload (repeatable); a directory is uploaded as a subfolder of the same name')
            ->addOption('include-subdirs', null, InputOption::VALUE_NONE, "When a --file path is a directory, also include its subdirectories recursively (default: only that directory's direct files)")
            ->addOption('chunk-size', null, InputOption::VALUE_REQUIRED, 'Override the chunk size in MB used for files above the chunking threshold')
            ->addOption('force-chunk-above', null, InputOption::VALUE_REQUIRED, 'Override the chunking threshold in MB, bypassing the ~4 GiB default (useful to test chunked uploads with a small file)')
            ->addOption('share-dir', null, InputOption::VALUE_NONE, 'Create (or reuse) a public link share for the target folder and print it')
            ->addOption('share-file', null, InputOption::VALUE_NONE, 'Create (or reuse) a public link share for the uploaded file and print it (only valid when exactly one file is uploaded)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $client = $this->client ??= NextcloudClient::fromEnv();

        $folder = (string) $input->getArgument('folder');
        $includeSubdirs = (bool) $input->getOption('include-subdirs');

        $paths = array_map(
            fn (string $path): string => realpath($path) ?: $path,
            $input->getOption('file')
        );

        if ($paths === []) {
            $io->error('No files given. Pass one or more --file=path options.');

            return Command::FAILURE;
        }

        $missing = array_filter($paths, fn (string $path): bool => ! file_exists($path));

        if ($missing !== []) {
            foreach ($missing as $path) {
                $io->error(sprintf('File not found: %s', $path));
            }

            return Command::FAILURE;
        }

        $jobs = [];

        foreach ($paths as $path) {
            foreach ($this->expand($path, $folder, $includeSubdirs) as $job) {
                $jobs[] = $job;
            }
        }

        if ($jobs === []) {
            $io->error('No files found to upload.');

            return Command::FAILURE;
        }

        if ($input->getOption('share-dir') && $input->getOption('share-file')) {
            $io->error('Pass either --share-dir or --share-file, not both.');

            return Command::FAILURE;
        }

        if ($input->getOption('chunk-size') !== null) {
            $client->setChunkSize(((int) $input->getOption('chunk-size')) * 1024 * 1024);
        }

        if ($input->getOption('force-chunk-above') !== null) {
            $client->setChunkThreshold(((int) $input->getOption('force-chunk-above')) * 1024 * 1024);
        }

        $remoteFolders = array_unique([...array_column($jobs, 'folder'), $folder]);

        try {
            foreach ($remoteFolders as $remoteFolder) {
                $client->ensureRemoteDirectory($remoteFolder);
            }
        } catch (NextcloudException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $uploadedPaths = [];
        $failed = false;

        foreach ($jobs as $job) {
            $remotePath = $this->uploadOne($io, $client, $job['folder'], $job['local']);

            if ($remotePath === null) {
                $failed = true;

                continue;
            }

            $uploadedPaths[] = $remotePath;
        }

        if ($failed) {
            return Command::FAILURE;
        }

        if ($input->getOption('share-dir')) {
            try {
                $link = $client->shareLink($folder);
            } catch (NextcloudException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            $io->success(sprintf('Share link: %s', $link));
        }

        if ($input->getOption('share-file')) {
            if (count($uploadedPaths) !== 1) {
                $io->error(sprintf(
                    '--share-file requires exactly one file to be uploaded (got %d); use --share-dir to share the whole folder instead.',
                    count($uploadedPaths)
                ));

                return Command::FAILURE;
            }

            try {
                $link = $client->shareLink($uploadedPaths[0]);
            } catch (NextcloudException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            $io->success(sprintf('Share link: %s', $link));
        }

        return Command::SUCCESS;
    }

    /**
     * Expand a raw --file path into one or more upload jobs. A plain file
     * becomes a single job under $folder. A directory becomes one job per
     * file found inside it, placed under $folder/<directory name> — its own
     * subfolder structure is preserved only when $includeSubdirs is true,
     * otherwise only the directory's direct files are included.
     *
     * @return array<int, array{local: string, folder: string}>
     */
    private function expand(string $path, string $folder, bool $includeSubdirs): array
    {
        if (is_file($path)) {
            return [['local' => $path, 'folder' => $folder]];
        }

        $root = sprintf('%s/%s', trim($folder, '/'), basename(rtrim($path, '/')));

        $finder = new Finder;
        $finder->files()->in($path);

        if (! $includeSubdirs) {
            $finder->depth('== 0');
        }

        $jobs = [];

        foreach ($finder as $file) {
            /** @var SplFileInfo $file */
            $relativeDir = $file->getRelativePath();

            $jobs[] = [
                'local' => $file->getPathname(),
                'folder' => $relativeDir === '' ? $root : sprintf('%s/%s', $root, $relativeDir),
            ];
        }

        return $jobs;
    }

    private function uploadOne(SymfonyStyle $io, NextcloudClient $client, string $folder, string $file): ?string
    {
        $size = (int) filesize($file);
        $chunked = $size > $client->chunkThreshold();

        $progress = null;

        try {
            $result = $client->upload($file, $folder, function (int $chunk, int $total) use (&$progress, $io) {
                if ($progress === null) {
                    $progress = new ProgressBar($io, $total);
                    $progress->start();
                }

                $progress->advance();
            });
        } catch (NextcloudException $e) {
            if ($progress !== null) {
                $progress->finish();
                $io->newLine();
            }

            $io->error(sprintf('%s: %s', basename($file), $e->getMessage()));

            return null;
        }

        if ($progress !== null) {
            $progress->finish();
            $io->newLine();
        }

        if ($result['skipped']) {
            $io->writeln(sprintf('%s (%s) — unchanged, skipped', basename($file), self::formatBytes($size)));

            return $result['path'];
        }

        $io->writeln(sprintf(
            '%s (%s)%s',
            basename($file),
            self::formatBytes($size),
            $chunked ? ' — chunked upload' : ''
        ));
        $io->writeln(sprintf('  → /%s (verified)', $result['path']));

        return $result['path'];
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return sprintf('%.2f %s', $size, $units[$unit]);
    }
}
