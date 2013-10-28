<?php

TinyStage::go();

class TinyStage
{
	private static $config;

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
	private static $config;

	/**
	 * @var TinyStageDB
	 */
	private static $left;

	/**
	 * @var TinyStageDB
	 */
	private static $right;

	public static $intel;

	public static function setup( $config )
	{
		self::$left = new TinyStageDB( $config->left );

		self::$right = new TinyStageDB( $config->right );

		self::$config = $config;
	}

	public static function sync()
	{
		foreach ( self::$left->tables as $left_table ) {
			$right_table = self::$right->tables->find( $left_table->name );

			if ( !$right_table ) continue;

			if ( !self::hasUpdates( $left_table, $right_table ) ) continue;

			$table_fields = self::getFields($left_table);

			$table_id = array_shift($table_fields)['Field'];

			// Prepare statements for selecting and inserting entries
			$stmt_left = self::$left->prepareSelect( $left_table );

			$stmt_right = self::$right->prepareSelect( $left_table, $table_id );

			$stmt_update = self::$right->prepareUpdate( $right_table, $table_fields );

			if (!$stmt_left->execute()) continue;

			while ($left_row = $stmt_left->fetch(PDO::FETCH_ASSOC)) {
				$stmt_right->bindValue(":".$table_id, $left_row[$table_id]);
				$stmt_right->execute();

				$right_row = $stmt_right->fetch(PDO::FETCH_ASSOC);

				$same = true;
				foreach ( $left_row as $j => $left_field ) {
					if ( $right_row[$j] !== $left_field ) {
						$same = false;

						break;
					}
				}

				if ( $same ) continue;

				foreach ( $table_fields as $field ) {
					$stmt_update->bindValue(":".$field['Field'], $field['Field']);
				}

				$stmt_update->execute();
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

	private static function getFields( $table )
	{
		$q = self::$left->prepare('describe '.$table->name);

		$q->execute();

		return $q->fetchAll(PDO::FETCH_COLUMN);
	}

	public static function close()
	{
		self::$left = null;
		self::$right = null;
	}
}

class TinyStageDB extends PDO
{
	private $name;

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

		$this->tables = new TinyStageDBTableIterator(
			$this->query( 'show table status from'.$this->name )
		);
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
	public function __construct( $array, $flags=0, $converted=array() )
	{
		foreach ( $array as $table ) {
			$converted[] = new TinyStageDBTable( $table );
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
	public function __construct( $data )
	{
		foreach ( $data as $k => $v ) {
			$this->{strtolower($k)} = $v;
		}
	}

}
