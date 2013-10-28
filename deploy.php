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
		if ( !file_exists( __DIR__.'/../config.json' ) ) {
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
		if ( !empty( TinyStageDBSync::$intel ) ) {
			self::$config->db->intel = TinyStageDBSync::$intel;
		}

		self::$config->last_update = (int) gmdate('U');

		file_put_contents( __DIR__.'/../config.json', json_encode(self::$config) );
	}

	private static function checkAuth()
	{
		if ( !isset( $_GET['auth'] ) || !isset( self::$config->auth ) ) {
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
		foreach ( self::$left->tables as $lt ) {
			$rt = self::$right->tables->find( $lt->name );

			if ( !$rt ) continue;

			if ( !self::hasUpdates( $lt, $rt ) ) continue;

			if (!$lt::$select->execute()) continue;

			while ( $lt_row = $lt::$select->fetch(PDO::FETCH_ASSOC) ) {
				$rt_row = $rt::fetchRow( $lt_row[$lt::$tableId] );

				$same = true;
				foreach ( $lt_row as $j => $lt_field ) {
					if ( $rt_row[$j] !== $lt_field ) {
						$same = false;

						break;
					}
				}

				if ( $same ) continue;

				foreach ( $lt::$tableFields as $field ) {
					$lt::$update->bindValue(":".$field['Field'], $field['Field']);
				}

				$lt::$update->execute();
			}
		}

	}

	private static function hasUpdates( $left, $right )
	{
		// Check whether there actually are any recent changes
		if ( $left->update_time == $right->update_time ) {
			return false;
		}

		// Check whether the updates happened since the last TinyStage Update
		if ( $left->update_time > TinyStage::$last_update ) {
			return true;
		}

		if ( $right->update_time > TinyStage::$last_update ) {
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
		return $this->query( 'show table status from'.$this->name );
	}

	public function prepareSelect( $table, $id=null )
	{
		if ( empty($id) ) {
			return $this->prepare(
				'select * from '.$table->name
			);
		} else {
			return $this->prepare(
				'select * from '.$table->name.' where '.$id.'=:'.$id
			);
		}
	}

	public function prepareUpdate( $table, $fields, $inserts=array() )
	{
		foreach ( $fields as $field ) {
			$inserts[] = $field['Field'].'=:'.$field['Field'];
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
			return $this->current();
		}

		// If not try the next
		$this->next();

		if ( $this->current()->name == $name ) {
			return $this->current();
		}

		// Otherwise start from the beginning
		$this->rewind();

		while( $this->valid() ) {
			if ( $this->current()->name == $name ) {
				return $this->current();
			}
		}

		return false;
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
		$q = $db->prepare('describe '.$this->name);

		$q->execute();

		return $q->fetchAll(PDO::FETCH_COLUMN);
	}

}
