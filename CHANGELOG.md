# Changelog

Notable changes to this project will be documented in this file.

## [1.0.0]

- Switch to MYSQLi
- Support for empty tables. `SHOW TABLE CREATE` checksum is now used in the repository
- Support for specifying a subset of databases to back up (suports wildcards)
- Update docs & license


## [0.9]

- PSR2, password via env


## [0.8]

- Wildcard support


## [0.7]

- Switch to using mysqldump client


## [0.6]

- Add bin2hex option (default) for blob fields


# [0.5]

- Table caching (local copy)


## [0.4]

- Fix bug where unescaped table names potentially caused issues (`Group`)


## [0.3]

- Add UFT-8 encoding for MySQL connection & file writing
