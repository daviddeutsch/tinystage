<?php

TinyStage::go();

class TinyStage
{
	private static $config;

	public static function go()
	{
		if ( !file_exists( __DIR__.'/../config.json' ) ) {
			header("HTTP/1.0 401 Unauthorized"); exit;
		}

		self::loadConfig();

		self::checkAuth();

		self::gitPull();

		self::syncDB();
	}

	public static function loadConfig()
	{
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

	public static function syncDB()
	{
		$dbc = self::$config->db;

		$db_left = new PDO(
			$dbc->left->dsn,
			$dbc->left->user,
			$dbc->left->password
		);

		$db_right = new PDO(
			$dbc->right->dsn,
			$dbc->right->user,
			$dbc->right->password
		);

		$left = $db_left->query( 'show table status from'.$dbc->dbleft->db );
		$right = $db_right->query( 'show table status from'.$dbc->dbright->db );

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


		}
	}
}
