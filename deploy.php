<?php

TinyStage::go();

class TinyStage
{
	/**
	 * @var stdClass
	 */
	private static $config;

	/**
	 * @var int timestamp
	 */
	public static $last_update;

	public static function go()
	{
		self::loadConfig();

		self::checkAuth();

		self::gitPull();

		self::syncDB();

		self::storeConfig();
	}

	private static function loadConfig()
	{
		if ( !file_exists(__DIR__.'/../config.json') ) {
			self::authError();
		}

		self::$config = json_decode( file_get_contents(__DIR__.'/../config.json') );

		if ( isset( self::$config->last_update ) ) {
			self::$last_update = self::$config->last_update;
		} else {
			self::$last_update = (int) gmdate('U', 0);
		}
	}

	private static function storeConfig()
	{
		self::$config->last_update = (int) gmdate('U');

		file_put_contents( __DIR__.'/../config.json', json_encode(self::$config) );
	}

	private static function checkAuth()
	{
		if ( !isset($_GET['auth']) || !isset(self::$config->auth) ) {
			self::authError();
		}

		if ( $_GET['auth'] != self::$config->auth ) {
			self::authError();
		}
	}

	private static function gitPull()
	{
		shell_exec( 'git pull origin ' . self::$config->branch );
	}

	private static function syncDB()
	{
		$sync = new tsDBSync( self::$config->db );

		$sync->sync();
	}

	private static function authError()
	{
		header("HTTP/1.0 401 Unauthorized"); exit;
	}
}

class tsDBSync
{
	/**
	 * @var stdClass
	 */
	private $config;

	/**
	 * @var tsDB
	 */
	private $left;

	/**
	 * @var tsDB
	 */
	private $right;

	public function __construct( $config )
	{
		$this->left = new tsDB( $config->left );

		$this->right = new tsDB( $config->right );

		$this->config = $config;
	}

	public function sync()
	{
		$iterator = new tsDBSyncIterator(
			$this->left->tables,
			$this->right->tables
		);

		foreach ( $iterator as $entry ) {
			if ( !$iterator->valid() ) continue;

			$entry->left->sync( $entry->right );
		}

		$this->close();
	}

	public function close()
	{
		$this->left = null;
		$this->right = null;
	}
}

class tsDB extends PDO
{
	/**
	 * @var string
	 */
	private $name;

	/**
	 * @var tsDBTableIterator
	 */
	public $tables;

	public function __construct( $config )
	{
		$this->name = $config->db;

		parent::__construct(
			$config->dsn,
			$config->user,
			$config->password,
			array(PDO::ATTR_PERSISTENT => true)
		);

		$this->tables = new tsDBTableIterator( $this );
	}

	public function getTableStatus()
	{
		return $this->query(
			'show table status from'.$this->name,
			PDO::FETCH_OBJ
		)->fetch();
	}

	public function prepareSelect( $table, $id=null )
	{
		if ( empty($id) ) {
			return $this->prepare(
				'select * from '.$table->name
			);
		} else {
			return $this->prepare(
				'select * from '.$table->name.' where '.$id.' = :'.$id
			);
		}
	}

	public function prepareUpdate( $table, $fields, $inserts=array() )
	{
		foreach ( $fields as $field ) {
			$inserts[] = $field['Field'].' = :'.$field['Field'];
		}

		return $this->prepare(
			'update '.$table->name.' set '.implode(', ', $inserts)
		);
	}
}

class tsDBTableIterator extends ArrayIterator
{
	public function __construct( tsDB $db, $converted=array() )
	{
		foreach ( $db->getTableStatus() as $table ) {
			$converted[] = new tsDBTable( $table, $db );
		}

		parent::__construct( $converted );
	}

	public function find( $name )
	{
		// First check whether the current entry is the one we're looking for
		if ( $this->current()->name == $name ) {
			return true;
		}

		// If not try the next
		$this->next();

		if ( $this->current()->name == $name ) {
			return true;
		}

		// Otherwise start from the beginning
		$this->rewind();

		while ( $this->valid() ) {
			if ( $this->current()->name == $name ) {
				return true;
			}

			$this->next();
		}

		return false;
	}

	public function equalTime( tsDBTableIterator $other )
	{
		return $this->current()->updated == $other->current()->updated;
	}

	public function hasUpdate()
	{
		return $this->current()->updated > TinyStage::$last_update;
	}
}

class tsDBSyncIterator implements Iterator
{
	/**
	 * @var tsDBTableIterator
	 */
	private $l;

	/**
	 * @var tsDBTableIterator
	 */
	private $r;

	public function __construct( $left, $right )
	{
		$this->l = $left;
		$this->r = $right;
	}

	public function current()
	{
		return (object) array(
			'left'  => $this->l->current(),
			'right' => $this->r->current()
		);
	}

	public function key()
	{
		return (object) array(
			'left'  => $this->l->key(),
			'right' => $this->r->key()
		);
	}

	public function next()
	{
		$this->l->next();
		$this->r->next();
	}

	public function valid()
	{
		if ( $this->r->find( $this->l->current()->name ) ) {
			return false;
		}

		if ( !$this->hasUpdates() ) {
			return false;
		}

		return $this->l->valid() && $this->r->valid();
	}

	public function rewind()
	{
		$this->l->rewind();
		$this->r->rewind();
	}

	private function hasUpdates()
	{
		// Check whether there actually are any recent changes
		if ( $this->l->equalTime($this->r) ) {
			return false;
		}

		if ( $this->l->hasUpdate() || $this->r->hasUpdate() ) {
			return true;
		}

		return false;
	}
}

class tsDBTable
{
	/**
	 * @var string
	 */
	public $name;

	/**
	 * @var int
	 */
	public $created;

	/**
	 * @var int
	 */
	public $updated;

	/**
	 * @var PDOStatement
	 */
	private $select;

	/**
	 * @var PDOStatement
	 */
	private $selectId;

	/**
	 * @var PDOStatement
	 */
	private $update;

	/**
	 * @var array
	 */
	private $fields;

	/**
	 * @var string
	 */
	private $id;

	public function __construct( $data, tsDB $db )
	{
		foreach ( $data as $k => $v ) {
			$this->fields[$k] = $v;
		}

		$this->name = $data['Name'];

		$this->created = $data['Create_time'];

		$this->updated = $data['Update_time'];

		$this->fields = $this->getFields($db);

		$this->id = $this->getId($db);

		// Prepare statements for selecting and inserting entries
		$this->select = $db->prepareSelect( $this );

		$this->selectId = $db->prepareSelect( $this, $this->id );

		$this->update = $db->prepareUpdate( $this, $this->fields );
	}

	private function fetchRow( $id )
	{
		$this->selectId->bindValue(":".$this->id, $id);
		$this->selectId->execute();

		return $this->selectId->fetch(PDO::FETCH_ASSOC);
	}

	private function getId()
	{
		return array_shift($this->fields)['Field'];
	}

	private function getFields( tsDB $db )
	{
		return $db->query('describe '.$this->name, PDO::FETCH_OBJ)->fetch();
	}

	public function sync( tsDBTable $right )
	{
		if ( !$this->select->execute() ) return;

		while ( $row = $this->select->fetch(PDO::FETCH_ASSOC) ) {
			$right_row = $right->fetchRow( $row[$this->id] );

			$same = true;
			foreach ( $row as $j => $field ) {
				if ( $right_row[$j] !== $field ) {
					$same = false;

					break;
				}
			}

			if ( $same ) continue;

			foreach ( $this->fields as $field ) {
				$this->update->bindValue(":".$field['Field'], $field['Field']);
			}

			$this->update->execute();
		}
	}
}
