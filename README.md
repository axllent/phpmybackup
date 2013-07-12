PHPMyBackup - A PHP MySQL differential backup script
====================================================

A PHP MySQL differential backup script
--------------------------------------

PHPMyBackup is a PHP script designed for backing up an entire MySQL
server on the commandline. What makes it unique is it only uses use
**differential** methods to dump only the changes as it keeps a local
copy of all the synced databases & tables.

Software features
-----------------

-   Only download changed/altered tables (checksum)
-   Allows skipping of specified databases from backups (supports wildcard)
-   Allows skipping of specified tables or table-data (supports wildcard)
-   Integrates with \`mysqldump\` client for individual sql dumps
-   Backup rotation

Limitations
-----------

-   No database locking during backup. because a separate \`mysqldump\`
    is called for every table download, only table locking is used.
-   Tables with no data (either empty or manually specified) do not
    contain a checksum, so a new backups of table structure is always
    made (extremely small).
-   This has been tested in several environments, but your own full
    testing is always advised!

Requirements
------------

-   A MySQL user on the server with ‘SELECT’ & ‘LOCK TABLES’ permissions
-   \`tar\` with bzip2 support (used for compressing backups)
-   PHP CLI on backup host with MySQL support
-   \`mysqldump\` (used for actual table dumping)

Usage
-----

    require('/path/to/phpmybackup.php');
    $db = new MYSQL_DUMP;
    $db->dbhost = 'server.com';
    $db->dbuser = 'backup-user';
    $db->dbpwd = 'backup-password';
    $db->backupsToKeep = 30;
    $db->showDebug = false;
    $db->backupDir = '/mnt/backups/mysql/';
    $db->ignoreList = array('test','unimportant_db');
    $db->emptyList = array('largedb.large_table1','largedb.cachetable');
    $db->dumpDatabases();

-   The above command will dump all databases except for ‘test’ and
    ‘unimportant\_db’ from server.com
-   The data from two tables, large\_table1 & cachetable, (found in
    database largedb) will be ignored, however the table structure will
    be backed up. This is especially handy if you have temporary tables
    or large tables which contain unimportant / cached data.
-   A total of 30-days of backups will be kept. **Note**: Backups are
    named by date (yyyy-mm-dd.tar.bz2), so a maximum of 1 backup per day
    can be kept. If the script is re-run on the same day, the repository
    is synced and the existing daily backup overwritten.
