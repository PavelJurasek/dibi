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
class PdoDriver extends BasePdoDriver
{
	protected function getErrmode(): int
	{
		return PDO::ERRMODE_EXCEPTION;
	}


	protected function initServerVersion(): ?string
	{
		try {
			return $this->connection->getAttribute(PDO::ATTR_SERVER_VERSION);
		} catch (\PDOException $e) {
			return null;
		}
	}


	/**
	 * Executes the SQL query.
	 * @throws Dibi\DriverException
	 */
	public function query(string $sql): ?Dibi\ResultDriver
	{
		try {
			$res = $this->connection->query($sql);
			$this->affectedRows = $res->rowCount();
			return $res->columnCount() ? $this->createResultDriver($res) : null;

		} catch (\PDOException $pdoException) {
			$this->affectedRows = null;
			throw $this->createException($pdoException->errorInfo, $sql);
		}
	}


	public function getInsertId(?string $sequence): ?int
	{
		try {
			return Helpers::intVal($this->connection->lastInsertId($sequence));
		} catch (\PDOException $pdoException) {
			throw $this->createException($pdoException->errorInfo, '');
		}
	}


	public function begin(?string $savepoint = null): void
	{
		try {
			$this->connection->beginTransaction();
		} catch (\PDOException $pdoException) {
			throw $this->createException($pdoException->errorInfo, 'START TRANSACTION');
		}
	}


	public function commit(?string $savepoint = null): void
	{
		try {
			$this->connection->commit();
		} catch (\PDOException $pdoException) {
			throw $this->createException($pdoException->errorInfo, 'COMMIT');
		}
	}


	public function rollback(?string $savepoint = null): void
	{
		try {
			$this->connection->rollBack();
		} catch (\PDOException $pdoException) {
			throw $this->createException($pdoException->errorInfo, 'ROLLBACK');
		}
	}
}
