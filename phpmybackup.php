<?php
/**
 * MySQL dumper for tables / databases
 */

class MYSQL_DUMP
{
    public $dbhost = ''; // MySQL host
    public $dbuser = ''; // MySQL username
    public $dbpwd = ''; // MySQL password

    /*
     * Array of database names to back up (supports wildcards)
     * Defaults to all
     * eg: ['database1', test*']
    */
    public $includeDatabases = ['*'];

    /*
     * Array of database names to ignore (supports wildcards)
     * Completely ignores database and all it's tables
     * eg: ['database1', test*']
    */
    public $ignoreDatabases = [];

    /*
     * Array of complete tables to ignore (includes database names - supports wildcards)
     * Format must include database "table.database"
     * eg: ['database1.mytable', 'test.ignore*']
    */
    public $ignoreTables = [];

    /*
     * Array of tables to ignore data (includes database names - supports wildcards)
     * Table structure will be backed up, but no data
     * Format must include database "table.database"
     * eg: ['database1.mytable', 'test.ignore*']
    */
    public $emptyTables = [];

    public $showDebug = false;
    public $dropTables = true;
    public $hex4blob = true;
    public $createDatabase = true;
    public $lineEnd = "\n";
    public $backupDir = 'out';
    public $backupRepository = false;
    public $backupsToKeep = 180;
    public $header = '';
    public $timezone = 'Pacific/Auckland';
    public $tar_binary = '/bin/tar';
    public $mysqldump_binary = '/usr/bin/mysqldump';
    public $savePermissions = 0664; // Save files with the following permissions

    public function dumpDatabases()
    {
        if (!function_exists('mysqli_connect')) {
            $this->errorMessage('Your PHP has no mysqli support, existing.');
            return false;
        }

        date_default_timezone_set($this->timezone);
        $this->backupFormat = date('Y-m-d');
        $this->backupDir = rtrim($this->backupDir, '/');
        if (!$this->backupRepository) {
            $this->backupRepository = $this->backupDir . '/repo';
        }
        $this->liveDatabases = [];

        // Export MySQL password
        passthru('export MYSQL_PWD="' . $this->dbpwd . '"');

        $this->con = new mysqli($this->dbhost, $this->dbuser, $this->dbpwd);
        if ($this->con->connect_errno) {
            $this->errorMessage('Cannot connect to ' . $this->dbhost . ' (' . $this->con->connect_error);
            return false;
        }
        $utf = $this->db_query('SET NAMES utf8');

        if (!is_dir($this->backupDir) || !is_writable($this->backupDir)) {
            $this->errorMessage('The temporary directory you have configured (' . $this->backupDir . ') is either non existant or not writable');
            return false;
        }

        if (!is_dir($this->backupRepository)) {
            $mr = @mkdir($this->backupRepository, 0755, true);
            if (!$mr) {
                $this->errorMessage('Cannot create the Repository ' . $this->backupRepository);
                return false;
            }
        }

        if (!is_writable($this->backupRepository)) {
            $this->errorMessage('Cannot write to Repository ' . $this->backupRepository);
            return false;
        }

        if (is_dir($this->backupDir . '/' . $this->backupFormat)) {
            $this->recursive_remove_directory($this->backupDir . '/' . $this->backupFormat);
        }

        $this->header  = '-- PHP-MySql Dump' . $this->lineEnd ;
        $this->header .= '-- Host: ' . $this->dbhost . $this->lineEnd;
        $this->header .= '-- Date: ' . date('F j, Y, g:i a') . $this->lineEnd;
        $this->header .= '-- -------------------------------------------------' . $this->lineEnd;

        $sql = $this->db_query('SELECT VERSION()');
        $row = $sql->fetch_array(MYSQLI_NUM);
        $this->header .= '-- Server version ' . $row[0] . $this->lineEnd . $this->lineEnd;

        $this->header .= '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;' . $this->lineEnd;
        $this->header .= '/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;' . $this->lineEnd;
        $this->header .= '/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;' . $this->lineEnd;
        $this->header .= '/*!40101 SET NAMES utf8 */;' . $this->lineEnd;
        $this->header .= '/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;' . $this->lineEnd;
        $this->header .= '/*!40103 SET TIME_ZONE=\'+00:00\' */;' . $this->lineEnd;
        $this->header .= '/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;' . $this->lineEnd;
        $this->header .= '/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;' . $this->lineEnd;
        $this->header .= '/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\' */;' . $this->lineEnd;
        $this->header .= '/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;' . $this->lineEnd;

        array_push($this->ignoreDatabases, 'information_schema');
        array_push($this->emptyTables, 'mysql.general_log');
        array_push($this->emptyTables, 'mysql.slow_log');

        $tmp = [];
        foreach ($this->includeDatabases as $e) {
            array_push($tmp, str_replace('_STARREDMATCH_', '(.*)', preg_quote(str_replace('*', '_STARREDMATCH_', $e), '/')));
        }
        $this->includeDatabases = '(' . implode($tmp, '|') . ')';

        $tmp = [];
        foreach ($this->emptyTables as $e) {
            array_push($tmp, str_replace('_STARREDMATCH_', '(.*)', preg_quote(str_replace('*', '_STARREDMATCH_', $e), '/')));
        }
        $this->emptyTables = '(' . implode($tmp, '|') . ')';

        $tmp = [];
        foreach ($this->ignoreTables as $e) {
            array_push($tmp, str_replace('_STARREDMATCH_', '(.*)', preg_quote(str_replace('*', '_STARREDMATCH_', $e), '/')));
        }
        $this->ignoreTables = '(' . implode($tmp, '|') . ')';

        $tmp = [];
        foreach ($this->ignoreDatabases as $e) {
            array_push($tmp, str_replace('_STARREDMATCH_', '(.*)', preg_quote(str_replace('*', '_STARREDMATCH_', $e), '/')));
        }
        $this->ignoreDatabases = '(' . implode($tmp, '|') . ')';

        $q = $this->db_query('SHOW DATABASES');
        while ($row = $q->fetch_array(MYSQLI_NUM)) {
            if (preg_match('/^' . $this->includeDatabases . '$/', $row[0])) {
                if (preg_match('/^' . $this->ignoreDatabases . '$/', $row[0])) {
                    $this->debug('- Ignoring database ' . $row[0]);
                    if (is_dir($this->backupRepository . '/' . $row[0])) {// Remove reposity copy of excluded databases if any
                        $this->debug('- found old repository of ' . $row[0] . ' - deleting');
                        $this->recursive_remove_directory($this->backupRepository . '/' . $row[0]);
                    }
                } else {
                    $this->liveDatabases[$row[0]] = [];
                    $this->syncTables($row[0]);
                }
            }
        }
        $this->debug('- Closing MySQL connection');
        $this->con->close();

        /* Now we remove any old databases */
        $dir_handle = @opendir($this->backupRepository) or die("Unable to open $path");
        while ($dir = readdir($dir_handle)) {
            if ($dir!='.' && $dir!='..') {
                if (!isset($this->liveDatabases[$dir])) {
                    $this->debug('- Found old database - deleting ' . $dir);
                    $this->recursive_remove_directory($this->backupRepository . '/' . $dir);
                }
            }
        }
        @closedir($this->dumpDir);

        $this->generateDbDumps();

        $this->debug('Compressing backups');
        exec('cd ' . $this->backupDir . ' ; ' . $this->tar_binary . ' -Jcf ' . $this->backupDir . '/' . $this->backupFormat . '.tar.xz ' . $this->backupFormat . ' > /dev/null');
        chmod($this->backupDir . '/' . $this->backupFormat . '.tar.xz', $this->savePermissions);
        if (!$this->recursive_remove_directory($this->backupDir . '/' . $this->backupFormat)) {
            $this->errorMessage('Cannot delete the directory ' . $this->backupDir . '/' . $this->backupFormat);
            return false;
        }

        $this->debug('Deleting old backups');
        $this->rotateFiles($this->backupDir);
    }

    private function syncTables($db)
    {
        $this->dumpDir = $this->backupRepository . '/' . $db;
        if (!is_dir($this->dumpDir)) {
            mkdir($this->dumpDir, 0755);
        }

        $d = $this->con->select_db($db);
        if (!$d) {
            $this->errorMessage('Cannot open database `' . $db . '`');
            return false;
        }
        $tbls = $this->db_query('SHOW TABLE STATUS FROM `' . $db . '`');
        $existingDBs = [];

        while ($row = $tbls->fetch_array(MYSQLI_ASSOC)) {
            $tblName = $row['Name'];
            $tblUpdate = $row['Update_time'];

            $cssql = $this->db_query('CHECKSUM TABLE `' . $tblName . '`');

            while ($csrow = $cssql->fetch_array(MYSQLI_ASSOC)) {
                $tblChecksum = $csrow['Checksum'];
            }

            if ($tblChecksum == null || preg_match('/^' . $this->emptyTables . '$/', $db . '.' . $tblName)) {
                $tblChecksum = 0;
            }

            /* Create create checksum */
            $create_sql = $this->db_query('SHOW CREATE TABLE `' . $tblName . '`');
            while ($create = $create_sql->fetch_array(MYSQLI_ASSOC)) {
                $tblChecksum .= '-' . substr(base_convert(md5($create['Create Table']), 16,32), 0, 12);
            }

            if ($row['Engine'] == null) {
                $row['Engine'] = 'View';
            }

            if (preg_match('/^' . $this->ignoreTables . '$/', $db . '.' . $tblName)) {
                $this->debug('- Ignoring table ' . $db . '.' . $tblName);
            } elseif (is_file($this->dumpDir . '/' . $tblName . '.' . $tblChecksum . '.' . strtolower($row['Engine']) . '.sql')) {
                $this->debug('- Repo version of ' . $db . '.' . $tblName . ' is current (' . $row['Engine'] . ')');
                array_push($this->liveDatabases[$db], $tblName . '.' . $tblChecksum . '.' . strtolower($row['Engine']));
            } else {
                array_push($this->liveDatabases[$db], $tblName . '.' . $tblChecksum . '.' . strtolower($row['Engine'])); // For later check & delete of missing ones
                $this->debug('+ Backing up new version of ' . $db . '.' . $tblName . ' (' . $row['Engine'] . ')');

                $dump_options = array(
                    '-C', // Compress connection
                    '-h' . $this->dbhost, // host
                    '-u' . $this->dbuser, // user
                    '--compact' // no need for database info for every table
                );

                if ($this->hex4blob) {
                    array_push($dump_options, '--hex-blob');
                }

                if (!$this->dropTables) {
                    array_push($dump_options, '--skip-add-drop-table');
                }

                if (strtolower($row['Engine']) == 'csv') {
                    $this->debug('- Skipping table locks for CSV table ' . $db . '.' . $tblName);
                    array_push($dump_options, '--skip-lock-tables');
                }

                if (preg_match('/^' . $this->emptyTables . '$/', $db . '.' . $tblName)) {
                    $this->debug('- Ignoring data for ' . $db . '.' . $tblName);
                    array_push($dump_options, '--no-data');
                } elseif (strtolower($row['Engine']) == 'memory') {
                    $this->debug('- Ignoring data for Memory table ' . $db . '.' . $tblName);
                    array_push($dump_options, '--no-data');
                } elseif (strtolower($row['Engine']) == 'view') {
                    $this->debug('- Ignoring data for View table ' . $db . '.' . $tblName);
                    array_push($dump_options, '--no-data');
                }

                $temp = tempnam(sys_get_temp_dir(), 'sqlbackup-');

                putenv('MYSQL_PWD=' . $this->dbpwd);

                $exec = passthru($this->mysqldump_binary . ' ' . implode($dump_options, ' ') . ' ' . $db . ' ' . $tblName . ' > ' . $temp);
                if ($exec != '') {
                    @unlink($temp);
                    $this->errorMessage('Unable to dump file to ' . $temp . ' ' . $exec);
                } else {
                    /* Make sure only complete files get saved */
                    chmod($temp, $this->savePermissions);
                    rename($temp, $this->dumpDir . '/' . $row['Name'] . '.' . $tblChecksum . '.' . strtolower($row['Engine']) . '.sql');
                    /* Set the file timestamp if supported */
                    if (!is_null($row['Update_time'])) {
                        @touch($this->dumpDir . '/' . $row['Name'] . '.' . $tblChecksum . '.' . strtolower($row['Engine']) . '.sql', strtotime($row['Update_time']));
                    }
                }
            }
        }

        /* Delete old tables if existing */
        $dir_handle = @opendir($this->dumpDir) or die("Unable to open $path");
        while ($file = readdir($dir_handle)) {
            if ($file != '.' && $file != '..') {
                if (!in_array(substr($file, 0, -4), $this->liveDatabases[$db])) {
                    $this->debug('- Found old table - deleting ' . $file);
                    unlink($this->dumpDir . '/' . $file);
                }
            }
        }
        @closedir($this->dumpDir);
    }

    private function generateDbDumps()
    {
        $mr = @mkdir($this->backupDir . '/' . $this->backupFormat, 0755, true);
        if (!$mr) {
            $this->errorMessage('Cannot create the backup directory '.$this->backupFormat);
            return false;
        }
        $dirs = [];
        $dir_handle = @opendir($this->backupRepository) or die('Unable to open ' . $this->backupRepository);
        while ($file = readdir($dir_handle)) {
            if ($file != '.' && $file != '..') {
                array_push($dirs, $file);
            }
        }

        closedir($dir_handle);
        sort($dirs);
        foreach ($dirs as $db) {
            $returnSql = $this->header;
            if ($this->createDatabase) {
                $returnSql .= '/*!40000 DROP DATABASE IF EXISTS `' . $db . '`*/; ' . $this->lineEnd;
                $returnSql .= 'CREATE DATABASE `' . $db . '`;' . $this->lineEnd . $this->lineEnd;
                $returnSql .= 'USE `' . $db . '`;' . $this->lineEnd . $this->lineEnd;
            }
            $fp = @fopen($this->backupDir . '/' . $this->backupFormat . '/' . $db . '.sql', 'wb');
            @fwrite($fp, $returnSql);
            @fclose($fp);

            $files = scandir($this->backupRepository . '/' . $db);
            $viewsql = '';
            $standardsql = '';
            $sqlfiles = [];
            $viewfiles = [];
            foreach ($files as $file) {
                if (preg_match('/^([a-zA-Z0-9_\-]+)\.([0-9]+)\-([a-z0-9]+)\.([a-z0-9]+)\.sql/', $file, $sqlmatch)) {
                    if ($sqlmatch[3]== 'view') {
                        array_push($viewfiles, $this->backupRepository . '/' . $db . '/' . $file);
                    } else {
                        array_push($sqlfiles, $this->backupRepository . '/' . $db . '/' . $file);
                    }
                }
            }

            /* Add all sql dumps in database */
            foreach ($sqlfiles as $f) {
                $this->chunked_copy_to($f, $this->backupDir . '/' . $this->backupFormat . '/' . $db . '.sql');
            }

            /* Add View tables after */
            foreach ($viewfiles as $f) {
                $this->chunked_copy_to($f, $this->backupDir . '/' . $this->backupFormat . '/' . $db . '.sql');
            }
        }
    }

    /* To prevent memory overload, fila A is copied 10MB at a time to file B */
    private function chunked_copy_to($from, $to)
    {
        $buffer_size = 10485760; // 10 megs at a time, you can adjust this.
        $ret = 0;
        $fin = fopen($from, 'rb');
        $fout = fopen($to, 'a');
        if (!$fin || !$fout) {
            die('Unable to copy ' . $fin . ' to ' . $fout);
        }
        while (!feof($fin)) {
            $ret += fwrite($fout, fread($fin, $buffer_size));
        }
        fclose($fin);
        fclose($fout);
        return $ret; // return number of bytes written
    }

    /* Rotate backups and delete old ones */
    private function rotateFiles($backup_directory)
    {
        $filelist = [];
        if (is_dir($backup_directory)) {
            if ($dh = opendir($backup_directory)) {
                while (($file = readdir($dh)) !== false) {
                    if (($file != '.') && ($file != '..') && (filetype($backup_directory . '/' . $file) == 'file')) {
                        $filelist[] = $file;
                    }
                }
                closedir($dh);
                sort($filelist); // Make sure it's listed in the correct order
                if (count($filelist) > $this->backupsToKeep) {
                    $too_many = (count($filelist) - $this->backupsToKeep);
                    for ($j=0;$j<$too_many; $j++) {
                        unlink($backup_directory . '/' . $filelist[$j]);
                    }
                }
                unset($filelist); // Uset $filelist[] array
            }
        }
    }

    private function errorMessage($msg)
    {
        echo $msg . $this->lineEnd;
    }

    private function debug($msg)
    {
        if ($this->showDebug) {
            echo $msg . $this->lineEnd;
        }
    }

    private function db_query($query)
    {
        if ($result = $this->con->query($query)) {
            return $result;
        } else {
            $this->errorMessage($this->con->error);
            return false;
        }
    }

    private function recursive_remove_directory($directory, $empty=false)
    {
        if (substr($directory, -1) == '/') {
            $directory = substr($directory, 0, -1);
        }
        if (!file_exists($directory) || !is_dir($directory)) {
            return false;
        } elseif (!is_readable($directory)) {
            return false;
        } else {
            $handle = opendir($directory);
            while (false !== ($item = readdir($handle))) {
                if ($item != '.' && $item != '..') {
                    $path = $directory . '/' . $item;
                    if (is_dir($path)) {
                        $this->recursive_remove_directory($path);
                    } else {
                        unlink($path);
                    }
                }
            }
            closedir($handle);
            if ($empty == false) {
                if (!rmdir($directory)) {
                    return false;
                }
            }
            return true;
        }
    }
}
