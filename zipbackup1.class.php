<?php

	/* vim: set ts=4 sw=4 sts=4 et: */
	/**
	 * Class ZipBackup
	 *
	 * @author     BaD ClusteR
	 * @license    http://www.gnu.org/licenses/gpl.html GPL license agreement
	 * @version    1.0
	 * @link       http://badcluster.ru
	 *
	 * Automatic site backups creating. The class creates zip archive with site files structure and database backups inside.
	 * You may include or exclude specific files, folders and databases.
	 *
	 */

	class ZipBackup
	{
		/**
		 * @var string Folder that will be root in the archive. Usually it is also the root directory for the site.
		 */
		private $currDir = "";
		private $rules = array('fs' => array('exclude' => array(), 'include' => array()), 'db' => array());
		/**
		 * @var array Array of file names that will be ignored in every directory. For example, adding element ".htaccess" will exclude Apache files from the archive.
		 */
		private $ignoredFileNames = array();
		/**
		 * @var string Mask for archive name. Possible placeholders: %DATE%.
		 */
		private $archiveNameMask = "backup_%DATE%.zip";
		/**
		 * @var string Client encoding
		 */
		private $clientEncoding = "utf8";
		/**
		 * @var string Directory for temporary archive storing
		 */
		private $tmpDir;
		/**
		 * @var ZipArchive Link for the archive file
		 */
		private $arcLink = NULL;
		/**
		 * @var mysqli SQL connection for extracting databases
		 */
		private $sql = NULL;

		public function __construct($tempDir, $currDir = "")
		{
			if (empty($currDir))
				$currDir = $_SERVER['DOCUMENT_ROOT'];
			$this->currDir = $currDir;
			$this->tmpDir = $tempDir;
		}

		public function setArciveNameMask($mask)
		{
			$this->archiveNameMask = $mask;
		}

		public function chDir($currDir)
		{
			$this->currDir = $currDir;
		}

		/**
		 * Add a folder/file path (relative to the root) that will be excluded from the archive. Adding "*" will exclude all files (except database dumps) from the archive.
		 *
		 * @param string $path Path
		 *
		 * @since 1.0
		 */
		public function addExcludeFS($path)
		{
			if (!in_array($path, $this->rules['fs']['exclude']))
				$this->rules['fs']['exclude'][] = $path;
		}

		/**
		 * Add o folder/file path (relative to the root) that will be included to the archive
		 *
		 * @param string $path Path
		 *
		 * @since 1.0
		 */
		public function addIncludeFS($path)
		{
			if (!in_array($path, $this->rules['fs']['include']))
				$this->rules['fs']['include'][] = $path;
		}

		/**
		 * Add MySQL database to dump
		 *
		 * @param string $dbName Database name
		 * @param array $connOptions Array with connection options
		 * @param array $tables Tables list (if empty, all tables will be in the dump)
		 * @param array $exTables List of tables that will be excluded from the dump
		 *
		 * @since 1.0
		 */
		public function addDB($dbName, $connOptions = array(), $tables = array(), $exTables = array())
		{
			$this->rules['db'][] = array('name' => $dbName, 'include' => $tables, 'exclude' => $exTables, 'options' => $connOptions);
		}

		/**
		 * Add ignored file name. Files with this name will be ignored in all folders.
		 *
		 * @param string $filename File name
		 *
		 * @since 1.0
		 */
		public function addIgnoredFileName($filename)
		{
			if (!in_array($filename, $this->ignoredFileNames))
				$this->ignoredFileNames[] = $filename;
		}

		private function getFilesTree($prefix = "", $level = 0)
		{
			if (!empty($prefix) && $prefix[sizeof($prefix) - 1] != "/")
				$prefix .= "/";
			$tempList = scandir($this->currDir . "/" . $prefix);
			$list = array();
			foreach ($tempList as $key => $value)
			{
				if ($value != "." && $value != ".." && !in_array($prefix . $value, $this->rules['fs']['exclude']) && !in_array($value, $this->ignoredFileNames))
				{
					if (is_dir($this->currDir . "/" . $prefix . $value))
						$this->getFilesTree($prefix . $value, $level + 1);
					else
						$this->arcLink->addFile($this->currDir . "/" . $prefix . $value, $prefix . $value);
					$list[] = $value;
				}
			}
		}

		private function getArchiveName()
		{
			return str_replace(array("%DATE%"), array(date("Y.m.d")), $this->archiveNameMask);
		}

		private function query($query, $numIndexes = false)
		{
			if ($this->sql == null)
				return -1;
			$res = $this->sql->query($query, MYSQLI_USE_RESULT);
			if ($this->sql->error != '')
				return -2;
			if ($res === false)
				return false;
			$result = array();
			if (is_bool($res))
				return true;
			while ((!$numIndexes && $temp = $res->fetch_assoc()) || ($numIndexes && $temp = $res->fetch_array(MYSQLI_NUM)))
				$result[] = $temp;
			return $result;
		}

		private function getTableFields($tableName)
		{
			if ($this->sql == null)
				return -1;
			$data = $this->query("SHOW COLUMNS FROM $tableName", true);
			if ($this->sql->error != '')
				return -2;
			if ($data === false)
				return false;
			$res = array();
			foreach ($data as $key => $value)
				$res[] = array('name' => $value[0], 'type' => explode("(", $value[1])[0]);
			return $res;
		}

		public function archive()
		{
			$this->arcLink = new ZipArchive();
			$fName = tempnam($this->tmpDir, "bkp");
			if (!$this->arcLink->open($fName, ZipArchive::CREATE))
				return -1;
			if (!in_array("*", $this->rules['fs']['exclude']))
			{
				if (empty($this->rules['fs']['include']))
					$this->getFilesTree();
				else
					foreach ($this->rules['fs']['include'] as $key => $value)
						$this->getFilesTree($value);
			}
			foreach ($this->rules['db'] as $key => $value)
			{
				if (empty($value['name']))
					continue;
				if ($this->sql == null && (empty($value['options']['user']) || empty($value['options']['password'])))
				{
					trigger_error("ZipBackup: can't backup database $value[name] because connection settings are not set", E_USER_WARNING);
					continue;
				}
				if (!empty($value['options']['user']) && !empty($value['options']['password']))
				{
					if (empty($value['options']['host']))
						$value['options']['host'] = "localhost";
					if ($this->sql != null)
						$this->sql->close();
					$this->sql = new mysqli($value['options']['host'], $value['options']['user'], $value['options']['password'], $value['name']);
				}
				else
					$this->sql->select_db($value['name']);
				$this->sql->query("SET NAMES $this->clientEncoding");
				$tables = $this->query("SHOW TABLES", true);
				$res = "SET NAMES $this->clientEncoding;\n\n\n";
				foreach ($tables as $k => $v)
				{
					$tableName = $v[0];
					$res .= $this->query("SHOW CREATE TABLE $tableName", true)[0][1] . ";\n\n";
					$fields = $this->getTableFields($tableName);
					$sqlRes = $this->sql->query("SELECT * FROM $tableName");
					$values = "";
					while ($temp = $sqlRes->fetch_array(MYSQLI_NUM))
					{
						$values .= ((empty($values)) ? "(" : ", \n(");
						foreach ($temp as $k1 => $v1)
						{
							if (in_array($fields[$k1]['type'], array("tinyint", "smallint", "mediumint", "int", "bigint", "decimal", "float", "double", "real", "bit")))
								$values .= (($k1 == 0) ? "" : ", ") . $this->sql->real_escape_string($v1);
							else
								$values .= (($k1 == 0) ? "'" : ", '") . $this->sql->real_escape_string($v1) . "'";
						}
						$values .= ")";
					}
					if (!empty($values))
					{
						$res .= "INSERT INTO `$tableName` (";
						foreach ($fields as $k1 => $v1)
							$res .= (($k1 == 0) ? "`" : ", `") . $v1['name'] . "`";
						$res .= ") VALUES \n$values;\n\n\n\n";
					}
				}
				$this->arcLink->addFromString("$value[name].sql", $res);
			}
			$this->arcLink->close();
			header('Content-Description: File Transfer');
			header('Content-Disposition: attachment; filename=' . $this->getArchiveName());
			header('Content-Type: application/zip');
			header('Content-Length: ' . filesize($fName));
			header('Accept-Ranges: bytes');
			header('Content-Transfer-Encoding: binary');
			header('Expires: 0');
			header('Cache-Control: must-revalidate');
			header('Pragma: public');
			readfile($fName);
			unlink($fName);
			die;
		}
	}