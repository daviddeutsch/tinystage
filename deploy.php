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
	}

	public static function loadConfig()
	{
		if ( !file_exists( __DIR__.'/../config.json' ) ) {
			header("HTTP/1.0 401 Unauthorized"); exit;
		}

		self::$config = json_decode( file_get_contents(__DIR__.'/../config.json') );
	}

	public static function checkAuth()
	{
		if ( !isset( $_GET['auth'] ) || !isset( self::$config->auth ) ) {
			header("HTTP/1.0 401 Unauthorized"); exit;
		}

		if ( $_GET['auth'] != self::$config->auth ) {
			header("HTTP/1.0 401 Unauthorized"); exit;
		}
	}

	public static function gitPull()
	{
		shell_exec( 'git pull origin ' . self::$config->branch );
	}

	private static function syncDB()
	{
		TinyStageDBSync::setup( self::$config->db );

		TinyStageDBSync::sync();
	}
}

class TinyStageDBSync
{
	private static $config;

	private static $left;
	private static $right;

	private static $offset;

	public static function setup( $config )
	{
		self::$config = $config;

		self::$left = new PDO(
			self::$config->left->dsn,
			self::$config->left->user,
			self::$config->left->password,
			array(PDO::ATTR_PERSISTENT => true)
		);

		self::$right = new PDO(
			self::$config->right->dsn,
			self::$config->right->user,
			self::$config->right->password,
			array(PDO::ATTR_PERSISTENT => true)
		);
	}

	public static function sync()
	{
		$left = self::$left->query( 'show table status from'.self::$config->left->db );
		$right = self::$right->query( 'show table status from'.self::$config->right->db );

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

	public static function close()
	{
		self::$left = null;
		self::$right = null;
	}
}
