<?php

/**
 * This file is part of the Dibi, smart database abstraction layer (https://dibiphp.com)
 * Copyright (c) 2005 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Dibi\Drivers;

use Dibi;
use Dibi\Helpers;
use PDO;


/**
 * The driver for PDO.
 *
 * Driver options:
 *   - dsn => driver specific DSN
 *   - username (or user)
 *   - password (or pass)
 *   - options (array) => driver specific options {@see PDO::__construct}
 *   - resource (PDO) => existing connection
 *   - version
 */
class PdoWarningModeDriver extends BasePdoDriver
{
	protected function getErrmode(): int
	{
		return PDO::ERRMODE_WARNING;
	}


	protected function initServerVersion(): ?string
	{
		return @$this->connection->getAttribute(PDO::ATTR_SERVER_VERSION); // @ - may be not supported
	}


	/**
	 * Executes the SQL query.
	 * @throws Dibi\DriverException
	 */
	public function query(string $sql): ?Dibi\ResultDriver
	{
		if ($res = @$this->connection->query($sql)) { // intentionally @ to catch warnings in warning PDO mode
			$this->affectedRows = $res->rowCount();
			return $res->columnCount() ? $this->createResultDriver($res) : null;
		}

		$this->affectedRows = null;
		throw $this->createException(
			$this->connection->errorInfo(),
			$sql,
		);
	}


	public function getInsertId(?string $sequence): ?int
	{
		$lastInsertId = $this->connection->lastInsertId($sequence);

		if ($lastInsertId === false) {
			$err = $this->connection->errorInfo();
			throw new Dibi\DriverException("SQLSTATE[$err[0]]: $err[2]", $err[1]);
		}

		return Helpers::intVal($lastInsertId);
	}


	public function begin(?string $savepoint = null): void
	{
		if (!$this->connection->beginTransaction()) {
			$err = $this->connection->errorInfo();
			throw new Dibi\DriverException("SQLSTATE[$err[0]]: $err[2]", $err[1]);
		}
	}


	public function commit(?string $savepoint = null): void
	{
		if (!$this->connection->commit()) {
			$err = $this->connection->errorInfo();
			throw new Dibi\DriverException("SQLSTATE[$err[0]]: $err[2]", $err[1]);
		}
	}


	public function rollback(?string $savepoint = null): void
	{
		if (!$this->connection->rollBack()) {
			$err = $this->connection->errorInfo();
			throw new Dibi\DriverException("SQLSTATE[$err[0]]: $err[2]", $err[1]);
		}
	}
}
