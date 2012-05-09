PHPMyBackup
========
**A PHP MySQL differential backup script**

PHPMyBackup is a PHP script designed for backing up an entire MySQL server. What makes it unique is it only uses use **differential** methods to dump only the changes as it keeps a local repository of all the synced databases & tables.

Usage
-------
    require('/path/to/phpmybackup.php');
    $db = new MYSQL_DUMP;
    $db->dbhost     = 'server.com';
    $db->dbuser     = 'backup-user';
    $db->dbpwd      = 'backup-password';
    $db->backupsToKeep = 30;
    $db->showDebug  = false;
    $db->backupDir  = '/home/ralph/backups/';
    $db->ignoreList = array('test');
    $db->emptyList  = array(
        'largedb.large_table1',
        'largedb.large_table2'
    );
    $db->dumpDatabases();

- The above command will dump all databases except for 'test' from server.com
- The data from two tables, large_table1 & large_table2, (found in database largedb) will be ignored, however the table structure will be backed up. This is especially handy if you have temporary tables or large tables which contain unimportant data.
- A total of 30-days of backups will be kept. **Note**: Backups are named by date (yyyy-mm-dd.tar.gz), so a maximum of 1 backup per day can be kept. If the script is re-run on the same day, the repository is synced and the existing daily backup overwritten.

Requirements
-----------------
- A MySQL user on the server with 'SELECT' & 'LOCK TABLES' permissions
- tar with bzip2 support
- PHP CLI on backup host with MySQL support