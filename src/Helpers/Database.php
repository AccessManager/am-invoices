<?php

namespace AccessManager\Invoices\Helpers;

use PDO;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;

class Database extends Capsule{

	public static function connect()
	{
		if( static::$instance === NULL ) {
			$db = require_once __DIR__."/../../../../../app/config/database.php";
			$configdb = $db['connections']['mysql'];
			$instance = new self;
			$instance->addConnection($configdb);
			$instance->setFetchMode(PDO::FETCH_CLASS);
			$instance->setEventDispatcher(new Dispatcher( new Container ) );
			$instance->setAsGlobal();
		}
		
	}

}

//end of file Database.php