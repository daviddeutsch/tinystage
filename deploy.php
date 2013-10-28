<?php

TinyStage::go();

class TinyStage
{
	private static $config;

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
	}

	private static function storeConfig()
	{
		if ( !empty( TinyStageDBSync::$intel ) ) {
			self::$config->db->intel = TinyStageDBSync::$intel;
		}

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
	}

	private static function authError()
	{
		header("HTTP/1.0 401 Unauthorized"); exit;
	}
}

class TinyStageDBSync
{
	private static $config;

	private static $left;
	private static $right;

	private static $offset;

	public static $intel;

	public static function setup( $config )
	{
		self::$left = new TinyStageDB( $config->left );

		self::$right = new TinyStageDB( $config->right );

		self::$config = $config;
	}

	public static function sync()
	{
		$left = self::$left->tableStatus();
		$right = self::$right->tableStatus();

		// Keep an offset in case right table has a reduced table set
		$offset = 0;
		foreach ( $left as $i => $left_table ) {
			$right_table = null;
			$right_exists = false;

			if ( $left_table['Name'] !== $right[$i+$offset]['Name'] ) {
				foreach ( $right as $j => $right_table ) {
					if ( $left_table['Name'] != $right_table['Name'] ) continue;

					$offset = $j-$i;

					$right_exists = true;
				}
			} else {
				$right_table = $right[$i+$offset];

				$right_exists = true;
			}

			if ( !$right_exists ) continue;

			// Check whether there actually are any recent changes
			if ( $left_table['Update_time'] == $right_table['Update_time'] ) {
				continue;
			}

			if ( $left_table['Update_time'] == $right_table['Update_time'] ) {
				continue;
			}

			$q = $db_left->prepare('DESCRIBE '.$left_table['Name']);
			$q->execute();

			$table_fields = $q->fetchAll(PDO::FETCH_COLUMN);

			$table_id = array_shift($table_fields)['Field'];

			// Prepare statements for selecting and inserting entries
			$stmt_left = $db_left->prepare(
				'SELECT * FROM '.$left_table['Name']
			);

			$stmt_right = $db_right->prepare(
				'SELECT * FROM '.$left_table['Name']
				.' WHERE '.$table_id.'=:'.$table_id
			);

			$inserts = array();
			foreach ( $table_fields as $field ) {
				$inserts[] = $field['Field'].'=:'.$field['Field'];
			}

			$stmt_update = $db_right->prepare(
				'UPDATE '.$left_table['Name']
				.' SET '.implode(', ', $inserts)
			);

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

	private static function correspondingTable( $left_table )
	{

	}

	public static function close()
	{
		self::$left = null;
		self::$right = null;
	}
}

class TinyStageDB
{
	private static $dbname;

	public function __construct( $config )
	{
		self::$dbname = $config->db;

		parent::__construct(
			$config->dsn,
			$config->user,
			$config->password,
			array(PDO::ATTR_PERSISTENT => true)
		);
	}

	public function tableStatus()
	{
		$this->query( 'show table status from'.self::$dbname );
	}
}
