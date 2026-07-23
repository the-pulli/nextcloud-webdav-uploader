# Nextcloud WebDAV Uploader

[![Latest Version on Packagist](https://img.shields.io/packagist/v/pulli/nextcloud-webdav-uploader.svg?style=flat-square)](https://packagist.org/packages/pulli/nextcloud-webdav-uploader)
[![Tests](https://github.com/the-pulli/nextcloud-webdav-uploader/actions/workflows/run-tests.yml/badge.svg)](https://github.com/the-pulli/nextcloud-webdav-uploader/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/pulli/nextcloud-webdav-uploader.svg?style=flat-square)](https://packagist.org/packages/pulli/nextcloud-webdav-uploader)

A standalone command-line tool for uploading files and folders to Nextcloud over WebDAV. Files above ~4 GiB automatically use Nextcloud's NG chunking API instead of a single PUT. Every upload is checksummed: unchanged files are skipped on a rerun, and every fresh upload is verified against the remote checksum afterwards.

> Using Laravel? See [laravel-nextcloud-webdav-uploader](https://github.com/pulli-org/laravel-nextcloud-webdav-uploader) for an Artisan command built on top of this package's `NextcloudClient`.

## Features

- Upload single files or whole directories (optionally recursive)
- Automatic chunked uploads for large files, with a progress bar
- SHA1 checksum skip-if-unchanged: rerunning against the same destination only transfers what changed
- Post-upload checksum verification, so a corrupted transfer fails loudly instead of silently
- Optional public share link creation

## Installation

You can install the package via composer:

```bash
composer require pulli/nextcloud-webdav-uploader
```

## Configuration

The tool reads its Nextcloud credentials from environment variables, either exported directly or via a `.env` file in the current working directory:

```bash
NEXTCLOUD_URL=https://cloud.example.com
NEXTCLOUD_USERNAME=jane
NEXTCLOUD_PASSWORD=app-password

# Optional overrides
NEXTCLOUD_CHUNK_THRESHOLD=4294967296  # bytes; default ~4 GiB
NEXTCLOUD_CHUNK_SIZE=536870912        # bytes; default 512 MiB
NEXTCLOUD_TIMEOUT=300                 # seconds
```

See [`.env.example`](.env.example).

## Usage

```bash
# Upload a single file into /Uploads
./nextcloud-webdav-uploader --file=/path/to/file.pdf

# Upload into a specific folder (created if missing)
./nextcloud-webdav-uploader Documents/2026 --file=/path/to/file.pdf

# Upload a directory (its direct files only) as a subfolder of the target folder
./nextcloud-webdav-uploader Backups --file=/path/to/folder

# ...including nested subdirectories, preserving structure
./nextcloud-webdav-uploader Backups --file=/path/to/folder --include-subdirs

# Multiple files/folders in one run
./nextcloud-webdav-uploader Backups --file=/path/a --file=/path/b.txt

# Create (or reuse) a public share link for the target folder afterwards
./nextcloud-webdav-uploader Shared --file=/path/to/file.pdf --share
```

Rerunning the same command only re-uploads files whose content actually changed — everything else is reported as `unchanged, skipped`.

### Options

| Option                | Description                                                                                 |
|------------------------|-----------------------------------------------------------------------------------------------|
| `folder`               | Target folder in Nextcloud, relative to the user's files root (default: `Uploads`)            |
| `--file`, `-f`         | Local file or directory to upload (repeatable)                                                |
| `--include-subdirs`    | Recurse into subdirectories of a `--file` directory, preserving structure                     |
| `--chunk-size`         | Override the chunk size in MB for files above the chunking threshold                          |
| `--force-chunk-above`  | Override the chunking threshold in MB (useful to exercise chunking without a huge test file)  |
| `--share`              | Create (or reuse) a public link share for the target folder and print it                      |

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [PuLLi](https://github.com/the-pulli)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
