#!/usr/bin/php
<?php
/* 
	MySQL differential backup for tables / databases
	Version: 0.6
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
	12/11/2011 - 0.6 - Add bin2hex option (default) for blob fields
	04/02/2011 - 0.5 - Table caching (local copy)
	18/10/2010 - 0.4 - Fix bug where unescaped table names potentially caused issues (`Group`)
	25/04/2010 - 0.3 - Add UFT-8 encoding for MySQL connection & file writing
*/

class MYSQL_DUMP{
	var $dbhost = "";
	var $dbuser = "";
	var $dbpwd = "";
	var $conflags = 'MYSQL_CLIENT_COMPRESS';
	var $ignoreList = array();
	var $emptyList = array();
	var $showDebug = false;
	var $dropTables = true;
	var $hex4blob = true;
	var $createDatabase = true;
	var $lineEnd = "\n";
	var $backupDir = 'out';
	var $backupRepository = false;
	var $lockTables = true;
	var $backupsToKeep = 180;
	var $header = '';
	var $timezone = 'Pacific/Auckland';
	
	function dumpDatabases(){
		date_default_timezone_set($this->timezone);
		$this->backupFormat = date('Y-m-d');
		$this->backupDir = rtrim($this->backupDir, '/');
		if (!$this->backupRepository) $this->backupRepository = $this->backupDir.'/repo';
		$this->liveDatabases = array();
		$this->con = @mysql_connect($this->dbhost, $this->dbuser, $this->dbpwd, $this->conflags);
		if(!$this->con) { $this->errorMessage('Cannot connect to '.$this->dbhost); return false;}
		$utf = $this->db_query('SET NAMES utf8');
		if (!is_dir($this->backupDir) || !is_writable($this->backupDir)){
			$this->errorMessage('The temporary directory you have configured ('.$this->backupDir.') is either non existant or not writable');
			return false;
		}
		
		if (!is_dir($this->backupRepository)){
			$mr = @mkdir($this->backupRepository, 0755, true);
			if (!$mr) {$this->errorMessage('Cannot create the Repository '.$this->backupRepository); return false;}
		}
		if(!is_writable($this->backupRepository)) {
			$this->errorMessage('Cannot write to Repository '.$this->backupRepository);
			return false;
		}
		
		if (is_dir($this->backupDir.'/'.$this->backupFormat)) $this->recursive_remove_directory($this->backupDir.'/'.$this->backupFormat);
		
		$this->header  = '-- PHP-MySql Dump v0.5'.$this->lineEnd ;
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
		
		array_push($this->ignoreList, 'information_schema');
		array_push($this->emptyList, 'mysql.general_log');
		array_push($this->emptyList, 'mysql.slow_log');
		$q = $this->db_query('SHOW DATABASES');
		while ($row = mysql_fetch_array($q)){
			if (in_array($row[0], $this->ignoreList)){
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
		mysql_close();
		/* Now we remove any old databases */
		$dir_handle = @opendir($this->backupRepository) or die("Unable to open $path");
		while ($dir = readdir($dir_handle)) {
			if ($dir!='.' && $dir!='..'){
				if (!isset($this->liveDatabases[$dir])){
					$this->debug('- Found old database - deleting '.$dir);
					$this->recursive_remove_directory($this->backupRepository.'/'.$dir);
				}
			}
		}
		@closedir($this->dumpDir);		
		
		$this->generateDbDumps();

		$this->debug('Compressing backups');
		exec('cd '.$this->backupDir.' ; /bin/tar -jcf '.$this->backupDir.'/'.$this->backupFormat.'.tar.bz2 '.$this->backupFormat.' > /dev/null');
		if (!$this->recursive_remove_directory($this->backupDir.'/'.$this->backupFormat)){
			$this->errorMessage('Cannot delete the directory '.$this->backupDir.'/'.$this->backupFormat);
			return false;
		}
		$this->debug('Deleting old backups');
		$this->rotateFiles($this->backupDir);
	}
	
	function syncTables($db){
		$this->dumpDir = $this->backupRepository.'/'.$db;
		if (!is_dir($this->dumpDir)) mkdir($this->dumpDir, 0755);
		
		$d = @mysql_select_db($db, $this->con);
		if(!$d) { $this->errorMessage('Cannot open database `'.$db.'`'); return false;}
		$tbls = $this->db_query('SHOW TABLE STATUS FROM `'.$db.'`');
		$existingDBs = array();
		while ($row = mysql_fetch_array($tbls)){
			$tblName = $row['Name'];
			$tblUpdate = $row['Update_time'];
			$cssql = $this->db_query('CHECKSUM TABLE `'.$tblName.'`');
			while ($csrow = mysql_fetch_assoc($cssql)){$tblChecksum = $csrow['Checksum'];}
			if ($tblChecksum == NULL) $tblChecksum = 0;
			if ($row['Engine'] == NULL) $row['Engine'] = 'View';
			
			if (in_array($db.'.'.$tblName, $this->ignoreList)){
				$this->debug('- Ignoring table '.$db.'.'.$tblName);
			}
			elseif ($tblChecksum != 0 && is_file($this->dumpDir.'/'.$tblName.'.'.$tblChecksum.'.'.strtolower($row['Engine']).'.sql')){
				//~ $this->debug('Repo version of '.$db.'.'.$tblName.' is current (InnoDB)');
				array_push($this->liveDatabases[$db], $tblName.'.'.$tblChecksum.'.'.strtolower($row['Engine']));
			}
			elseif ($row['Update_time'] &&  $tblChecksum == 0 && is_file($this->dumpDir.'/'.$tblName.'.'.$tblChecksum.'.'.strtolower($row['Engine']).'.sql') 
				&& date('Y-m-d H:i:s', filemtime($this->dumpDir.'/'.$row['Name'].'.'.$tblChecksum.'.'.strtolower($row['Engine']).'.sql')) == $row['Update_time']){
				//~ $this->debug('Repo version of '.$db.'.'.$tblName.' is current and contains no data');
				array_push($this->liveDatabases[$db], $tblName.'.'.$tblChecksum.'.'.strtolower($row['Engine']));
			}
			else {
				array_push($this->liveDatabases[$db], $tblName.'.'.$tblChecksum.'.'.strtolower($row['Engine'])); // For later check & delete of missing ones
				$this->debug('Backing up new version of '.$db.'.'.$tblName.' ('.$row['Engine'].')');
				$table_data = '';
				$table_data .= '--'.$this->lineEnd;
				$table_data .= '-- Table structure for table `'.$tblName.'`'.$this->lineEnd;
				$table_data .= '--'.$this->lineEnd.$this->lineEnd;
				if ($this->dropTables){
					if($row['Engine'] == 'View') // View
					$table_data .= 'DROP VIEW IF EXISTS `'.$tblName.'`;'.$this->lineEnd;
					else
						$table_data .= 'DROP TABLE IF EXISTS `'.$tblName.'`;'.$this->lineEnd;
				}
				$create = $this->db_query('SHOW CREATE TABLE `'.$tblName.'`');
				list($tbl, $structure) = mysql_fetch_row($create);
				
				if (preg_match('@CONSTRAINT|FOREIGN[\s]+KEY@', $structure)) {
					$sql_lines = explode($this->lineEnd, $structure);
					$sql_count = count($sql_lines);
					// lets find first line with constraints
					for ($i = 0; $i < $sql_count; $i++) {if (preg_match('@^[\s]*(CONSTRAINT|FOREIGN[\s]+KEY)@', $sql_lines[$i])) break;}
					$sql_lines[$i-1] = substr($sql_lines[$i-1], 0, -1); // Remove comma
					unset ($sql_lines[$i]);
					$structure = implode($this->lineEnd, $sql_lines);
				}
				
				$table_data .= $structure.';'.$this->lineEnd.$this->lineEnd;
				
				if (in_array($db.'.'.$tblName, $this->emptyList)){
					$this->debug('- Ignoring data for '.$db.'.'.$tblName);
				}
				elseif($row['Engine'] == 'MEMORY' ){
					$this->debug('- Ignoring data for Memory table '.$db.'.'.$tblName);
				}
				elseif($row['Engine'] == 'View' ){
					$this->debug('- Ignoring data for View table '.$db.'.'.$tblName);
				}
				else {
					/* Analize table */
					$this->dbstructure = array();
					$asql = $this->db_query('DESCRIBE `'.$tblName.'`');
					while ($asqlrow = mysql_fetch_assoc($asql)){
						$this->dbstructure[$asqlrow['Field']] = $asqlrow;
					}					
					$table_data .= '--'.$this->lineEnd;
					$table_data .= '-- Dumping data for table `'.$tblName.'`'.$this->lineEnd;
					$table_data .= '--'.$this->lineEnd.$this->lineEnd;
					
					$insertStatement = 'INSERT INTO `'.$tblName.'` VALUES ';
					/* get data */
					$tmp = array();
					$dataSql = $this->db_query('SELECT * FROM `'.$tblName.'`');
					while ($datarow = mysql_fetch_assoc($dataSql)){
						foreach($datarow as $key => $value){
							if ($this->dbstructure[$key]['Null'] == 'YES' && is_null($value)) $datarow[$key] = 'NULL';
							else if (preg_match('/int/', $this->dbstructure[$key]['Type']) && is_numeric($value)) $datarow[$key] = $value;
							else if (preg_match('/blob/', $this->dbstructure[$key]['Type']) && $this->hex4blob) {
								if (empty($value) && $value != '0') $datarow[$key] = '""';
								else $datarow[$key] = '0x'.bin2hex($value);
							}
							else $datarow[$key] = '"'.mysql_real_escape_string($value).'"';
						}
						array_push($tmp, '('.@implode(',',$datarow).')');
					}
					if (mysql_num_rows($dataSql) > 0){
						if($this->lockTables) {
							$table_data .= 'LOCK TABLES `'.$tblName.'` WRITE;'.$this->lineEnd;
							$table_data .= '/*!40000 ALTER TABLE `'.$tblName.'` DISABLE KEYS */;'.$this->lineEnd;
						}
						/* Dump Data */
						$table_data .= $insertStatement.implode(",\n",$tmp).';'.$this->lineEnd;
						if($this->lockTables) {
							$table_data .= '/*!40000 ALTER TABLE `'.$tblName.'` ENABLE KEYS */;'.$this->lineEnd;
							$table_data .= 'UNLOCK TABLES;'.$this->lineEnd.$this->lineEnd;
						}
					}
				}
				$this->save_sql($table_data, $this->dumpDir.'/'.$row['Name'].'.'.$tblChecksum.'.'.strtolower($row['Engine']).'.sql', $row['Update_time']);
			}
		}
		
		/* Delete old tables if existing */
		$dir_handle = @opendir($this->dumpDir) or die("Unable to open $path");
		while ($file = readdir($dir_handle)) {
			if ($file!='.' && $file!='..'){
				if (!in_array(substr($file, 0, -4), $this->liveDatabases[$db])){
					$this->debug('- Found old table - deleting '.$file);
					unlink($this->dumpDir.'/'.$file);
				}
			}
		}
		@closedir($this->dumpDir);
	}
	
	function generateDbDumps(){
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
		foreach ($dirs as $db){
			$returnSql = $this->header;
			if($this->createDatabase){
				$returnSql .= '/*!40000 DROP DATABASE IF EXISTS `'.$db.'`*/; '.$this->lineEnd;
				$returnSql .= 'CREATE DATABASE `'.$db.'`;'.$this->lineEnd.$this->lineEnd;
				$returnSql .= 'USE `'.$db.'`;'.$this->lineEnd.$this->lineEnd;
			}
			$dir_handle = @opendir($this->backupRepository.'/'.$db) or die('Unable to open '.$this->backupRepository.'/'.$db);
			$viewsql = '';
			$standardsql = '';
			while ($file = readdir($dir_handle)) {
				if ( preg_match('/^([a-z0-9_]+)\.([0-9]+)\.([a-z0-9]+)\.sql/i', $file, $sqlmatch) ){
					if ($sqlmatch[3]== 'view'){
						if ($this->dropTables) $returnSql .= 'DROP VIEW IF EXISTS `'.$sqlmatch[1].'`;'.$this->lineEnd.$this->lineEnd;
						$viewsql .= file_get_contents($this->backupRepository.'/'.$db.'/'.$file);
					} else {
						$standardsql .= file_get_contents($this->backupRepository.'/'.$db.'/'.$file);
					}
				}
			}
			closedir($dir_handle);
			$this->save_sql($returnSql.$standardsql.$viewsql,$this->backupDir.'/'.$this->backupFormat.'/'.$db.'.sql');
		}
	}
	
	
	function save_sql($sql,$sqlfile,$ts=false){
		$fp = @fopen($sqlfile,"wb");
		if(!is_resource($fp)){
			$this->errorMessage('Error: Unable to save file.');
			return false;
		}
		@fwrite($fp,$sql);
		@fclose($fp);
		if($ts) touch($sqlfile, strtotime($ts));
		return true;	
	}
	
	function rotateFiles($backup_directory){
		// file rotation
		if (is_dir($backup_directory)) {
			if ($dh = opendir($backup_directory)) {
				while (($file = readdir($dh)) !== false){
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
	
	function errorMessage($msg){echo $msg.$this->lineEnd;}
	
	function debug($msg){	if ($this->showDebug) echo $msg.$this->lineEnd;}
	
	function db_query($query) {
		$result = mysql_query($query);
		if (!$result) {
			$this->errorMessage(mysql_error());
			return false;
		}
		return $result;
	}
	
	function recursive_remove_directory($directory, $empty=false){
		if(substr($directory,-1) == '/'){$directory = substr($directory,0,-1);}
		if(!file_exists($directory) || !is_dir($directory)){return false;}
		elseif(!is_readable($directory)){	return false;}
		else{
			$handle = opendir($directory);
			while (false !== ($item = readdir($handle))){
				if($item != '.' && $item != '..'){
					$path = $directory.'/'.$item;
					if(is_dir($path)){$this->recursive_remove_directory($path);}
					else{unlink($path);}
				}
			}
			closedir($handle);
			if($empty == false){if(!rmdir($directory)){return false;}}
			return true;
		}
	}
}
?>
