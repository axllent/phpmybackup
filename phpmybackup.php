<?php
/*
	MySQL dumper for tables / databases
	Author: Ralph Slooten
	Website: http://www.axllent.org/
	------------------------------------------------------------------------
	License: Distributed under the Lesser General Public License (LGPL)
		http://www.gnu.org/copyleft/lesser.html
	This program is distributed in the hope that it will be useful - WITHOUT
	ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
	FITNESS FOR A PARTICULAR PURPOSE.
	------------------------------------------------------------------------
	Changelog:
	12/01/2013 - 0.8 - Wildcard support	
	11/01/2013 - 0.7 - Switch to using mysqldump client
	12/11/2011 - 0.6 - Add bin2hex option (default) for blob fields
	04/02/2011 - 0.5 - Table caching (local copy)
	18/10/2010 - 0.4 - Fix bug where unescaped table names potentially caused issues (`Group`)
	25/04/2010 - 0.3 - Add UFT-8 encoding for MySQL connection & file writing
*/

class MYSQL_DUMP{
	var $dbhost = ""; // MySQL Host
	var $dbuser = ""; // MySQL Username
	var $dbpwd = ""; // MySQL password
	var $conflags = 'MYSQL_CLIENT_COMPRESS';
	/*
	 * Array of database names to ignore (supports wildcards)
	 * Completely ignored database and all it's tables
	 * eg: array('database1', test*')
	*/
	var $ignoreDatabases = array();
	/*
	 * Array of complete tables to ignore (includes database names - supports wildcards)
	 * Format must include database "table.database"
	 * eg: array('database1.mytable', 'test.ignore*')
	*/
	var $ignoreTables = array();
	/*
	 * Array of tables to ignore data (includes database names - supports wildcards)
	 * Table structure will be backed up, but no data
	 * Format must include database "table.database"
	 * eg: array('database1.mytable', 'test.ignore*')
	*/
	var $emptyTables = array(); // array of tables to dump only structure and no data (includes database names - supports wildcards)
	var $showDebug = false;
	var $dropTables = true;
	var $hex4blob = true;
	var $createDatabase = true;
	var $lineEnd = "\n";
	var $backupDir = 'out';
	var $backupRepository = false;
	var $backupsToKeep = 180;
	var $header = '';
	var $timezone = 'Pacific/Auckland';
	var $tar_binary = '/bin/tar';
	var $mysqldump_binary = '/usr/bin/mysqldump';
	var $savePermissions = 0664; // Save files with the following permissions

	public function dumpDatabases() {
		date_default_timezone_set($this->timezone);
		$this->backupFormat = date('Y-m-d');
		$this->backupDir = rtrim($this->backupDir, '/');
		if (!$this->backupRepository) $this->backupRepository = $this->backupDir.'/repo';
		$this->liveDatabases = array();

		$this->con = mysql_connect($this->dbhost, $this->dbuser, $this->dbpwd, $this->conflags);
		if (!$this->con) {$this->errorMessage('Cannot connect to '.$this->dbhost); return false;}
		$utf = $this->db_query('SET NAMES utf8');

		if (!is_dir($this->backupDir) || !is_writable($this->backupDir)) {
			$this->errorMessage('The temporary directory you have configured ('.$this->backupDir.') is either non existant or not writable');
			return false;
		}

		if (!is_dir($this->backupRepository)) {
			$mr = @mkdir($this->backupRepository, 0755, true);
			if (!$mr) {$this->errorMessage('Cannot create the Repository '.$this->backupRepository); return false;}
		}
		if (!is_writable($this->backupRepository)) {
			$this->errorMessage('Cannot write to Repository '.$this->backupRepository);
			return false;
		}

		if (is_dir($this->backupDir.'/'.$this->backupFormat)) $this->recursive_remove_directory($this->backupDir.'/'.$this->backupFormat);


		$this->header  = '-- PHP-MySql Dump v0.8'.$this->lineEnd ;
		$this->header .= '-- Host: '.$this->dbhost.$this->lineEnd;
		$this->header .= '-- Date: '.date('F j, Y, g:i a').$this->lineEnd;
		$this->header .= '-- -------------------------------------------------'.$this->lineEnd;

		$sql = $this->db_query('SELECT VERSION()');
		$row = mysql_fetch_array($sql);
		$this->header .= '-- Server version '.$row[0].$this->lineEnd.$this->lineEnd;

		$this->header .= '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;'.$this->lineEnd;
		$this->header .= '/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;'.$this->lineEnd;
		$this->header .= '/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;'.$this->lineEnd;
		$this->header .= '/*!40101 SET NAMES utf8 */;'.$this->lineEnd;
		$this->header .= '/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;'.$this->lineEnd;
		$this->header .= '/*!40103 SET TIME_ZONE=\'+00:00\' */;'.$this->lineEnd;
		$this->header .= '/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;'.$this->lineEnd;
		$this->header .= '/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;'.$this->lineEnd;
		$this->header .= '/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\' */;'.$this->lineEnd;
		$this->header .= '/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;'.$this->lineEnd;

		array_push($this->ignoreDatabases, 'information_schema');
		array_push($this->emptyTables, 'mysql.general_log');
		array_push($this->emptyTables, 'mysql.slow_log');

		$tmp = array();
		foreach ($this->emptyTables as $e)
			array_push($tmp, str_replace('_STARREDMATCH_', '(.*)', preg_quote(str_replace('*', '_STARREDMATCH_', $e), '/')));
		$this->emptyTables = '('.implode($tmp, '|').')';

		$tmp = array();
		foreach ($this->ignoreTables as $e)
			array_push($tmp, str_replace('_STARREDMATCH_', '(.*)', preg_quote(str_replace('*', '_STARREDMATCH_', $e), '/')));
		$this->ignoreTables = '('.implode($tmp, '|').')';

		$tmp = array();
		foreach ($this->ignoreDatabases as $e)
			array_push($tmp, str_replace('_STARREDMATCH_', '(.*)', preg_quote(str_replace('*', '_STARREDMATCH_', $e), '/')));
		$this->ignoreDatabases = '('.implode($tmp, '|').')';

		$q = $this->db_query('SHOW DATABASES');
		while ($row = mysql_fetch_array($q)) {
			if (preg_match('/^'.$this->ignoreDatabases.'$/', $row[0])) {
				$this->debug('- Ignoring database '.$row[0]);
				if (is_dir($this->backupRepository.'/'.$row[0])) {// Remove reposity copy of excluded databases if any
					$this->debug('- found old repository of '.$row[0].' - deleting');
					$this->recursive_remove_directory($this->backupRepository.'/'.$row[0]);
				}
			}
			else {
				$this->liveDatabases[$row[0]] = array();
				$this->syncTables($row[0]);
			}
		}
		$this->debug('- Closing MySQL connection');
		mysql_close();

		/* Now we remove any old databases */
		$dir_handle = @opendir($this->backupRepository) or die("Unable to open $path");
		while ($dir = readdir($dir_handle)) {
			if ($dir!='.' && $dir!='..') {
				if (!isset($this->liveDatabases[$dir])) {
					$this->debug('- Found old database - deleting '.$dir);
					$this->recursive_remove_directory($this->backupRepository.'/'.$dir);
				}
			}
		}
		@closedir($this->dumpDir);

		$this->generateDbDumps();

		$this->debug('Compressing backups');
		exec('cd '.$this->backupDir.' ; '.$this->tar_binary.' -jcf '.$this->backupDir.'/'.$this->backupFormat.'.tar.bz2 '.$this->backupFormat.' > /dev/null');
		chmod($this->backupDir.'/'.$this->backupFormat.'.tar.bz2', $this->savePermissions);
		if (!$this->recursive_remove_directory($this->backupDir.'/'.$this->backupFormat)) {
			$this->errorMessage('Cannot delete the directory '.$this->backupDir.'/'.$this->backupFormat);
			return false;
		}

		$this->debug('Deleting old backups');
		$this->rotateFiles($this->backupDir);

	}

	private function syncTables($db) {
		$this->dumpDir = $this->backupRepository.'/'.$db;
		if (!is_dir($this->dumpDir)) mkdir($this->dumpDir, 0755);

		$d = @mysql_select_db($db, $this->con);
		if (!$d) { $this->errorMessage('Cannot open database `'.$db.'`'); return false;}
		$tbls = $this->db_query('SHOW TABLE STATUS FROM `'.$db.'`');
		$existingDBs = array();

		while ($row = mysql_fetch_array($tbls)) {
			$tblName = $row['Name'];
			$tblUpdate = $row['Update_time'];

			$cssql = $this->db_query('CHECKSUM TABLE `'.$tblName.'`');

			while ($csrow = mysql_fetch_assoc($cssql)) $tblChecksum = $csrow['Checksum'];

			if ($tblChecksum == NULL || preg_match('/^'.$this->emptyTables.'$/', $db.'.'.$tblName)) $tblChecksum = 0;

			if ($row['Engine'] == NULL) $row['Engine'] = 'View';

			if (preg_match('/^'.$this->ignoreTables.'$/',$db.'.'.$tblName)) $this->debug('- Ignoring table '.$db.'.'.$tblName);

			elseif ($tblChecksum != 0 && is_file($this->dumpDir.'/'.$tblName.'.'.$tblChecksum.'.'.strtolower($row['Engine']).'.sql')) {
				$this->debug('- Repo version of '.$db.'.'.$tblName.' is current ('.$row['Engine'].')');
				array_push($this->liveDatabases[$db], $tblName.'.'.$tblChecksum.'.'.strtolower($row['Engine']));
			}

			else {
				array_push($this->liveDatabases[$db], $tblName.'.'.$tblChecksum.'.'.strtolower($row['Engine'])); // For later check & delete of missing ones
				$this->debug('+ Backing up new version of '.$db.'.'.$tblName.' ('.$row['Engine'].')');

				$dump_options = array(
					'-C', // Compress connection
					'-h'.$this->dbhost, // Host
					'-u'.$this->dbuser, // User
					'-p'.$this->dbpwd, // Password
					'--compact' // no need to database info for every table
				);

				if ($this->hex4blob) array_push($dump_options, '--hex-blob');

				if (!$this->dropTables) array_push($dump_options, '--skip-add-drop-table');

				if (strtolower($row['Engine']) == 'csv') {
					$this->debug('- Skipping table locks for CSV table '.$db.'.'.$tblName);
					array_push($dump_options, '--skip-lock-tables');
				}

				if (preg_match('/^'.$this->emptyTables.'$/', $db.'.'.$tblName)) {
					$this->debug('- Ignoring data for '.$db.'.'.$tblName);
					array_push($dump_options, '--no-data');
				}
				elseif (strtolower($row['Engine']) == 'memory' ) {
					$this->debug('- Ignoring data for Memory table '.$db.'.'.$tblName);
					array_push($dump_options, '--no-data');
				}
				elseif (strtolower($row['Engine']) == 'view' ) {
					$this->debug('- Ignoring data for View table '.$db.'.'.$tblName);
					array_push($dump_options, '--no-data');
				}

				$temp = tempnam(sys_get_temp_dir(), 'sqlbackup-');

				$exec = passthru($this->mysqldump_binary.' '.implode($dump_options, ' ').' '.$db.' '.$tblName.' > '.$temp);
				if($exec != '') {
					@unlink($temp);
					$this->errorMessage('Unable to dump file to '.$temp. ' ' .$exec);
				}
				else {
					/* Make sure only complete files get saved */
					chmod($temp, $this->savePermissions);
					rename($temp, $this->dumpDir.'/'.$row['Name'].'.'.$tblChecksum.'.'.strtolower($row['Engine']).'.sql');
					/* Set the file timestamp if supported */
					if(!is_null($row['Update_time']))
						@touch($this->dumpDir.'/'.$row['Name'].'.'.$tblChecksum.'.'.strtolower($row['Engine']).'.sql', strtotime($row['Update_time']));
				}

			}
		}

		/* Delete old tables if existing */
		$dir_handle = @opendir($this->dumpDir) or die("Unable to open $path");
		while ($file = readdir($dir_handle)) {
			if ($file!='.' && $file!='..') {
				if (!in_array(substr($file, 0, -4), $this->liveDatabases[$db])) {
					$this->debug('- Found old table - deleting '.$file);
					unlink($this->dumpDir.'/'.$file);
				}
			}
		}
		@closedir($this->dumpDir);

	}

	private function generateDbDumps() {
		$mr = @mkdir($this->backupDir.'/'.$this->backupFormat, 0755, true);
		if (!$mr) {$this->errorMessage('Cannot create the backup directory '.$this->backupFormat); return false;}
		$dirs = array();
		$dir_handle = @opendir($this->backupRepository) or die('Unable to open '.$this->backupRepository);
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
				$returnSql .= '/*!40000 DROP DATABASE IF EXISTS `'.$db.'`*/; '.$this->lineEnd;
				$returnSql .= 'CREATE DATABASE `'.$db.'`;'.$this->lineEnd.$this->lineEnd;
				$returnSql .= 'USE `'.$db.'`;'.$this->lineEnd.$this->lineEnd;
			}
			$fp = @fopen($this->backupDir.'/'.$this->backupFormat.'/'.$db.'.sql',"wb");
			@fwrite($fp,$returnSql);
			@fclose($fp);

			$files = scandir($this->backupRepository.'/'.$db);
			$viewsql = '';
			$standardsql = '';
			$sqlfiles = array();
			$viewfiles = array();
			foreach ($files as $file) {
				if ( preg_match('/^([a-zA-Z0-9_\-]+)\.([0-9]+)\.([a-z0-9]+)\.sql/', $file, $sqlmatch) ) {

					if ($sqlmatch[3]== 'view')
						array_push($viewfiles, $this->backupRepository.'/'.$db.'/'.$file);

					else
						array_push($sqlfiles, $this->backupRepository.'/'.$db.'/'.$file);

				}
			}

			/* Add all sql dumps in database */
			foreach ($sqlfiles as $f)
				$this->chunked_copy_to($f, $this->backupDir.'/'.$this->backupFormat.'/'.$db.'.sql');

			/* Add View tables after */
			foreach ($viewfiles as $f)
				$this->chunked_copy_to($f, $this->backupDir.'/'.$this->backupFormat.'/'.$db.'.sql');

		}
	}

	/* To prevent memory overload, fila A is copied 10MB at a time to file B */
	private function chunked_copy_to($from, $to) {
		$buffer_size = 10485760; // 10 megs at a time, you can adjust this.
		$ret = 0;
		$fin = fopen($from, "rb");
		$fout = fopen($to, "a");
		if(!$fin || !$fout) die('Unable to copy '.$fin.' to '.$fout);
		while(!feof($fin))
			$ret += fwrite($fout, fread($fin, $buffer_size));
		fclose($fin);
		fclose($fout);
		return $ret; // return number of bytes written
	}

	/* Rotate backups and delete old ones */
	private function rotateFiles($backup_directory) {
		$filelist = array();
		if (is_dir($backup_directory)) {
			if ($dh = opendir($backup_directory)) {
				while (($file = readdir($dh)) !== false) {
					if ( ($file != '.') && ($file != '..') && (filetype($backup_directory.'/'.$file) == 'file') ) $filelist[] = $file;
				}
				closedir($dh);
				sort($filelist); // Make sure it's listed in the correct order
				if (count($filelist) > $this->backupsToKeep) {
					$too_many = ( count($filelist) - $this->backupsToKeep );
					for ($j=0;$j<$too_many; $j++) {unlink($backup_directory.'/'.$filelist[$j]);}
				}
				unset($filelist); // Uset $filelist[] array
			}
		}
	}

	private function errorMessage($msg) {echo $msg.$this->lineEnd;}

	private function debug($msg) {if ($this->showDebug) echo $msg.$this->lineEnd;}

	private function db_query($query) {
		$result = mysql_query($query);
		if (!$result) {
			$this->errorMessage(mysql_error());
			return false;
		}
		return $result;
	}

	private function recursive_remove_directory($directory, $empty=FALSE) {
		if (substr($directory,-1) == '/') {$directory = substr($directory,0,-1);}
		if (!file_exists($directory) || !is_dir($directory)) {return FALSE;}
		elseif (!is_readable($directory)) {return FALSE;}
		else {
			$handle = opendir($directory);
			while (FALSE !== ($item = readdir($handle))) {
				if ($item != '.' && $item != '..') {
					$path = $directory.'/'.$item;
					if (is_dir($path)) {$this->recursive_remove_directory($path);}
					else {unlink($path);}
				}
			}
			closedir($handle);
			if ($empty == FALSE) {if (!rmdir($directory)) {return FALSE;}}
			return TRUE;
		}
	}

}
