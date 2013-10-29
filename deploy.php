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
		TinyStageDBSync::setup( self::$config->db );

		TinyStageDBSync::sync();

		TinyStageDBSync::close();
	}

	private static function authError()
	{
		header("HTTP/1.0 401 Unauthorized"); exit;
	}
}

class TinyStageDBSync
{
	/**
	 * @var stdClass
	 */
	private static $config;

	/**
	 * @var TinyStageDB
	 */
	private static $left;

	/**
	 * @var TinyStageDB
	 */
	private static $right;

	public static function setup( $config )
	{
		self::$left = new TinyStageDB( $config->left );

		self::$right = new TinyStageDB( $config->right );

		self::$config = $config;
	}

	public static function sync()
	{
		$iterator = new TinyStageDBSyncIterator(
			self::$left->tables,
			self::$right->tables
		);

		foreach ( $iterator as $entry ) {
			if ( !$iterator->valid() ) continue;

			TinyStageDBTableSync::sync( $entry->left, $entry->right );
		}
	}

	private static function hasUpdates( $left, $right )
	{
		// Check whether there actually are any recent changes
		if ( $left->update_time == $right->update_time ) {
			return false;
		}

		// Check whether the updates happened since the last TinyStage Update
		if (
			( $left->update_time > TinyStage::$last_update )
			|| ( $right->update_time > TinyStage::$last_update )
		) {
			return true;
		}

		return false;
	}

	public static function close()
	{
		self::$left = null;
		self::$right = null;
	}
}

class TinyStageDB extends PDO
{
	/**
	 * @var string
	 */
	private $name;

	/**
	 * @var TinyStageDBTableIterator
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

		$this->tables = new TinyStageDBTableIterator( $this );
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

class TinyStageDBTableIterator extends ArrayIterator
{
	public function __construct( TinyStageDB $db, $converted=array() )
	{
		foreach ( self::$db->getTableStatus() as $table ) {
			$converted[] = new TinyStageDBTable( $table, $db );
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
}

class TinyStageDBSyncIterator implements Iterator
{
	/**
	 * @var TinyStageDBTableIterator
	 */
	private $left;

	/**
	 * @var TinyStageDBTableIterator
	 */
	private $right;

	public function __construct( $left, $right )
	{
		$this->left = $left;
		$this->right = $right;
	}

	public function current()
	{
		return (object) array(
			'left' =>$this->left->current(),
			'right' => $this->right->current()
		);
	}

	public function key()
	{
		return (object) array(
			'left' =>$this->left->key(),
			'right' => $this->right->key()
		);
	}

	public function next()
	{
		$this->left->next();
		$this->right->next();
	}

	public function valid()
	{
		if ( $this->right->find( $this->left->current()->name ) ) {
			return false;
		}

		if ( $this->hasUpdates() ) {
			return false;
		}

		return $this->left->valid() && $this->right->valid();
	}

	public function rewind()
	{
		$this->left->rewind();
		$this->right->rewind();
	}

	private function hasUpdates()
	{
		// Check whether there actually are any recent changes
		if ( $this->left->current()->update_time == $this->right->current()->update_time ) {
			return false;
		}

		// Check whether the updates happened since the last TinyStage Update
		if (
			( $this->left->current()->update_time > TinyStage::$last_update )
			|| ( $this->right->current()->update_time > TinyStage::$last_update )
		) {
			return true;
		}

		return false;
	}
}

class TinyStageDBTableSync
{
	public static function sync( TinyStageDBTable $left, TinyStageDBTable $right )
	{
		if ( !$left::$select->execute() ) return;

		while ( $left_row = $left::$select->fetch(PDO::FETCH_ASSOC) ) {
			$right_row = $right->fetchRow( $left_row[$left::$tableId] );

			$same = true;
			foreach ( $left_row as $j => $left_field ) {
				if ( $right_row[$j] !== $left_field ) {
					$same = false;

					break;
				}
			}

			if ( $same ) continue;

			foreach ( $left::$tableFields as $field ) {
				$left::$update->bindValue(":".$field['Field'], $field['Field']);
			}

			$left::$update->execute();
		}
	}
}

class TinyStageDBTable
{
	/**
	 * @var string
	 */
	public $name;

	/**
	 * @var PDOStatement
	 */
	public static $select;

	/**
	 * @var PDOStatement
	 */
	public static $selectId;

	/**
	 * @var PDOStatement
	 */
	public static $update;

	/**
	 * @var array
	 */
	public static $tableFields;

	/**
	 * @var string
	 */
	public static $tableId;

	public function __construct( $data, TinyStageDB $db )
	{
		foreach ( $data as $k => $v ) {
			$this->{strtolower($k)} = $v;
		}

		self::$tableFields = self::getFields($db);

		self::$tableId = self::getId($db);

		// Prepare statements for selecting and inserting entries
		self::$select = $db->prepareSelect( $this );

		self::$selectId = $db->prepareSelect( $this, self::$tableId );

		self::$update = $db->prepareUpdate( $this, self::$tableFields );
	}

	public function fetchRow( $id )
	{
		self::$select->bindValue(":".self::$tableId, $id);
		self::$select->execute();

		return self::$select->fetch(PDO::FETCH_ASSOC);
	}

	private function getId()
	{
		return array_shift(self::$tableFields)['Field'];
	}

	private function getFields( TinyStageDB $db )
	{
		return $db->query('describe '.$this->name, PDO::FETCH_OBJ)->fetch();
	}

}
