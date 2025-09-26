# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and this project adheres to Semantic Versioning where possible.

## [Unreleased]

### Added
- Automatic detection of Mroonga plugin state at install. If Mroonga is not ACTIVE, installation cleanly aborts with a bilingual (EN/JA) actionable message and the module remains uninstalled.
- Automatic engine switch for `fulltext_search` to `ENGINE=Mroonga`, with tokenizer `TokenMecab` set via COMMENT when available.
- Safe handling of foreign key on `owner_id`: dropped before engine switch and recreated on uninstall when missing.

### Changed
- Install sequence reordered to validate environment first, then write settings, then switch engine. Prevents partial settings writes on failure.

### Fixed
- Prevent fatal errors caused by accidental top-level code execution during module bootstrap.

## [4.0.0-mroonga.auto] - 2025-09-26

### Added
- Verified compatibility with Omeka S 4.x.
- Improved error messages and documentation for Mroonga/TokenMecab setup.

### Changed
- Codebase cleaned up to follow Omeka S module style (spacing, braces, comments).

