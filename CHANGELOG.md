# Changelog

All notable changes to `Nextcloud WebDAV Uploader` will be documented in this file.

## v1.0.1 - 2026-07-23

- **Breaking:** split `--share` into `--share-dir` (shares the destination folder, same as old `--share`) and `--share-file` (shares the single uploaded file; errors if more than one file was uploaded)
- Support Guzzle 8 and Symfony 8 components (`guzzlehttp/guzzle: ^7.9||^8.0`, `symfony/console` / `symfony/finder: ^7.2||^8.0`)
- Fix CI: enable `pcov` so Pest's "no code coverage driver" warning doesn't abort every run (`phpunit.xml` sets `failOnWarning="true"`)
