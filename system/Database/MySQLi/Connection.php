<?php namespace CodeIgniter\Database\MySQLi;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ConnectionInterface;

class Connection extends BaseConnection implements ConnectionInterface
{
	/**
	 * Database driver
	 *
	 * @var    string
	 */
	public $dbdriver = 'mysqli';

	/**
	 * Compression flag
	 *
	 * @var    bool
	 */
	public $compress = false;

	/**
	 * DELETE hack flag
	 *
	 * Whether to use the MySQL "delete hack" which allows the number
	 * of affected rows to be shown. Uses a preg_replace when enabled,
	 * adding a bit more processing to all queries.
	 *
	 * @var    bool
	 */
	public $deleteHack = true;

	/**
	 * Strict ON flag
	 *
	 * Whether we're running in strict SQL mode.
	 *
	 * @var    bool
	 */
	public $stricton;

	// --------------------------------------------------------------------

	/**
	 * Identifier escape character
	 *
	 * @var    string
	 */
	public $escapeChar = '`';

	// --------------------------------------------------------------------

	/**
	 * MySQLi object
	 *
	 * Has to be preserved without being assigned to $conn_id.
	 *
	 * @var    MySQLi
	 */
	protected $mysqli;

	//--------------------------------------------------------------------

	/**
	 * Connect to the database.
	 *
	 * @return mixed
	 */
	public function connect($persistent = false)
	{
		// Do we have a socket path?
		if ($this->hostname[0] === '/')
		{
			$hostname = null;
			$port     = null;
			$socket   = $this->hostname;
		}
		else
		{
			$hostname = ($persistent === true)
				? 'p:'.$this->hostname
				: $this->hostname;
			$port     = empty($this->port) ? null : $this->port;
			$socket   = null;
		}

		$client_flags = ($this->compress === true) ? MYSQLI_CLIENT_COMPRESS : 0;
		$this->mysqli = mysqli_init();

		$this->mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);

		if (isset($this->stricton))
		{
			if ($this->stricton)
			{
				$this->mysqli->options(MYSQLI_INIT_COMMAND,
					'SET SESSION sql_mode = CONCAT(@@sql_mode, ",", "STRICT_ALL_TABLES")');
			}
			else
			{
				$this->mysqli->options(MYSQLI_INIT_COMMAND,
					'SET SESSION sql_mode =
					REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
					@@sql_mode,
					"STRICT_ALL_TABLES,", ""),
					",STRICT_ALL_TABLES", ""),
					"STRICT_ALL_TABLES", ""),
					"STRICT_TRANS_TABLES,", ""),
					",STRICT_TRANS_TABLES", ""),
					"STRICT_TRANS_TABLES", "")'
				);
			}
		}

		if (is_array($this->encrypt))
		{
			$ssl = [];
			empty($this->encrypt['ssl_key']) OR $ssl['key'] = $this->encrypt['ssl_key'];
			empty($this->encrypt['ssl_cert']) OR $ssl['cert'] = $this->encrypt['ssl_cert'];
			empty($this->encrypt['ssl_ca']) OR $ssl['ca'] = $this->encrypt['ssl_ca'];
			empty($this->encrypt['ssl_capath']) OR $ssl['capath'] = $this->encrypt['ssl_capath'];
			empty($this->encrypt['ssl_cipher']) OR $ssl['cipher'] = $this->encrypt['ssl_cipher'];

			if ( ! empty($ssl))
			{
				if (isset($this->encrypt['ssl_verify']))
				{
					if ($this->encrypt['ssl_verify'])
					{
						defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT') &&
						$this->mysqli->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
					}
					// Apparently (when it exists), setting MYSQLI_OPT_SSL_VERIFY_SERVER_CERT
					// to FALSE didn't do anything, so PHP 5.6.16 introduced yet another
					// constant ...
					//
					// https://secure.php.net/ChangeLog-5.php#5.6.16
					// https://bugs.php.net/bug.php?id=68344
					elseif (defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT'))
					{
						$this->mysqli->options(MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT, true);
					}
				}

				$client_flags |= MYSQLI_CLIENT_SSL;
				$this->mysqli->ssl_set(
					isset($ssl['key']) ? $ssl['key'] : null,
					isset($ssl['cert']) ? $ssl['cert'] : null,
					isset($ssl['ca']) ? $ssl['ca'] : null,
					isset($ssl['capath']) ? $ssl['capath'] : null,
					isset($ssl['cipher']) ? $ssl['cipher'] : null
				);
			}
		}

		if ($this->mysqli->real_connect($hostname, $this->username, $this->password, $this->database, $port, $socket,
			$client_flags)
		)
		{
			// Prior to version 5.7.3, MySQL silently downgrades to an unencrypted connection if SSL setup fails
			if (
				($client_flags & MYSQLI_CLIENT_SSL)
				&& version_compare($this->mysqli->client_info, '5.7.3', '<=')
				&& empty($this->mysqli->query("SHOW STATUS LIKE 'ssl_cipher'")
				                      ->fetch_object()->Value)
			)
			{
				$this->mysqli->close();
				$message = 'MySQLi was configured for an SSL connection, but got an unencrypted connection instead!';
				log_message('error', $message);

				return ($this->db->db_debug) ? $this->db->display_error($message, '', true) : false;
			}

			if ( ! $this->mysqli->set_charset($this->charset))
			{
				log_message('error', "Database: Unable to set the configured connection charset ('{$this->charset}').");
				$this->mysqli->close();

				return ($this->db_debug) ? $this->display_error('db_unable_to_set_charset', $this->charset) : false;
			}

			return $this->mysqli;
		}

		return false;
	}

	//--------------------------------------------------------------------

	/**
	 * Keep or establish the connection if no queries have been sent for
	 * a length of time exceeding the server's idle timeout.
	 *
	 * @return mixed
	 */
	public function reconnect()
	{
		if ($this->connID !== false && $this->connID->ping() === false)
		{
			$this->connID = false;
		}
	}

	//--------------------------------------------------------------------

	/**
	 * Select a specific database table to use.
	 *
	 * @param string $databaseName
	 *
	 * @return mixed
	 */
	function setDatabase(string $databaseName)
	{
		if ($databaseName === '')
		{
			$databaseName = $this->database;
		}

		if ($this->connID->select_db($databaseName))
		{
			$this->database = $databaseName;

			return true;
		}

		return false;
	}

	//--------------------------------------------------------------------

	/**
	 * Returns a string containing the version of the database being used.
	 *
	 * @return mixed
	 */
	function getVersion()
	{
		if (isset($this->dataCache['version']))
		{
			return $this->dataCache['version'];
		}

		return $this->dataCache['version'] = $this->mysqli->server_info;
	}

	//--------------------------------------------------------------------

	/**
	 * Executes the query against the database.
	 *
	 * @param $sql
	 *
	 * @return mixed
	 */
	public function execute($sql)
	{
		return $this->connID->query($this->prepQuery($sql));
	}

	//--------------------------------------------------------------------

	/**
	 * Prep the query
	 *
	 * If needed, each database adapter can prep the query string
	 *
	 * @param    string $sql an SQL query
	 *
	 * @return    string
	 */
	protected function prepQuery($sql)
	{
		// mysqli_affected_rows() returns 0 for "DELETE FROM TABLE" queries. This hack
		// modifies the query so that it a proper number of affected rows is returned.
		if ($this->deleteHack === true && preg_match('/^\s*DELETE\s+FROM\s+(\S+)\s*$/i', $sql))
		{
			return trim($sql).' WHERE 1=1';
		}

		return $sql;
	}

	//--------------------------------------------------------------------

	/**
	 * Returns the total number of rows affected by this query.
	 *
	 * @return mixed
	 */
	public function affectedRows(): int
	{
		return $this->connID->affected_rows;
	}

	//--------------------------------------------------------------------
}