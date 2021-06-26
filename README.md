## **Note**: This software has been superseded by a completely different server/client backup called [MyBack](https://github.com/axllent/myback).

MyBack does not require an open MySQL port to the backup sever, and is a 
far more robust & actively-maintained backup system.

Please consider using MyBack instead, as PHPMyBackup is no longer maintained.

https://github.com/axllent/myback

---

PHPMyBackup - A PHP MySQL differential backup script
=====================================================


A PHP MySQL differential backup script
---------------------------------------

PHPMyBackup is a PHP script designed for backing up an entire MySQL
server on the commandline. What makes it unique is it only uses use
**differential** methods to dump only the changes as it keeps a local
copy of all the synced databases & tables.


Software features
-----------------

-   Only download changed/altered tables (checksum)
-   Allows specifying subset of databases for backups (supports wildcard)
-   Allows skipping of specified databases from backups (supports wildcard)
-   Allows skipping of specified tables or table-data (supports wildcard)
-   Integrates with `mysqldump` client for individual SQL dumps
-   Backup rotation


Limitations
-----------

-   No database locking during backup. because a separate \`mysqldump\`
    is called for every table download, only table locking is used.
-   This has been tested in several environments, but your own full testing
    is always advised!

Requirements
------------

-   A MySQL user on the server with ‘SELECT’ & ‘LOCK TABLES’ permissions
-   `tar` with `xz` support (used for compressing backups)
-   PHP CLI on backup host with **MySQLi** support
-   `mysqldump` (used for actual table dumping)

Usage Example
-------------

    require('phpmybackup.php');
    $db = new MYSQL_DUMP;
    $db->dbhost = 'server.com';
    $db->dbuser = 'backup-user';
    $db->dbpwd = 'backup-password';
    $db->backupsToKeep = 30;
    $db->showDebug = false;
    $db->backupDir = '/mnt/backups/mysql/';
    $db->ignoreDatabases = ['test','unimportant_db'];
    $db->emptyTables = ['largedb.large_table1','largedb.cachetable'];
    $db->dumpDatabases();

-   The above command will dump all databases except for `test` and
    `unimportant_db` from `server.com`
-   The data from two tables, `large_table1` & `cachetable`, (found in
    database `largedb`) will be ignored, however the table structure will
    be backed up. This is especially handy if you have temporary tables
    or large tables which contain unimportant / cached data.
-   A total of 30-days of backups will be kept. **Note**: Backups are
    named by date (yyyy-mm-dd.tar.xz), so a maximum of 1 backup per day
    can be kept. If the script is re-run on the same day, the repository
    is synced and the existing daily backup overwritten.

Notes
-----

By default all databases are backed up (`$db->includeDatabases = ['*']`).
You can limit this to a subset of your databases if you like.
