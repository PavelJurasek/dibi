<?php
/**
 * @dataProvider ../databases.ini != nothing, pdo
 */

// Context:
// When PDO connection is passed into Dibi it can be in (re)configured in various ways.
// This affects how connection is then internally handled.
// There should be no visible difference in Dibi behaviour regardless of PDO configuration.
//
// There are two cases that needs to be tested:
// 1. When query succeeds -> result returned
// 2. When query fails for various reasons -> proper exception should be generated


declare(strict_types=1);

use Dibi\Connection;
use Dibi\DriverException;
use Dibi\Drivers\PdoWarningModeDriver;
use Dibi\ForeignKeyConstraintViolationException;
use Dibi\NotNullConstraintViolationException;
use Dibi\UniqueConstraintViolationException;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


function buildPDOConnection(int $errorMode = null): PDO
{
	global $config;

	$config['driver'] = PdoWarningModeDriver::class;

	// used to parse config, establish connection
	$connection = new Connection($config);
	$dibiDriver = $connection->getDriver();
	assert($dibiDriver instanceof PdoWarningModeDriver);

	// hack: extract PDO connection from driver (no public interface for that)
	$connectionProperty = (new ReflectionClass($dibiDriver))
		->getProperty('connection');
	$connectionProperty->setAccessible(true);
	$pdo = $connectionProperty->getValue($dibiDriver);
	assert($pdo instanceof PDO);

	// check that error reporting is in PHPs default value
	assert($pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_WARNING);

	// override PDO error mode if provided
	if ($errorMode !== null) {
		$pdo->setAttribute(PDO::ATTR_ERRMODE, $errorMode);
	}
	return $pdo;
}


function buildDibiConnection(PDO $pdo): Connection
{
	$conn = new Connection(['resource' => $pdo, 'driver' => 'pdoWarningMode']);
	assert($conn->getDriver() instanceof PdoWarningModeDriver);
	return $conn;
}


$runTests = function (Connection $connection) use ($config) {
	$connection->loadFile(__DIR__ . "/data/$config[system].sql");
	if ($config['system'] === 'sqlite') { // @see issue #301
		$connection->query('PRAGMA foreign_keys=true');
	}

	// successful SELECT
	test('successful SELECT', function () use ($connection) {
		$result = $connection->query('SELECT `product_id`, `title` FROM `products` WHERE `product_id` = 1')->fetch();
		Assert::equal(['product_id' => 1, 'title' => 'Chair'], $result->toArray());
	});

	// Non-existing table: General exception should be generated
	Assert::exception(function () use ($connection) {
		$connection->query('SELECT * FROM `nonexisting`');
	}, DriverException::class);

	// Duplicated INSERT: UniqueConstraintViolationException
	Assert::exception(function () use ($connection) {
		$connection->query("INSERT INTO `products` (`product_id`, `title`) VALUES (1, 'Chair')");
	}, UniqueConstraintViolationException::class);

	// INSERT with NULL: NotNullConstraintViolationException
	Assert::exception(function () use ($connection) {
		$connection->query('INSERT INTO `products` (`title`) VALUES (NULL)');
	}, NotNullConstraintViolationException::class);

	// INSERT with NULL: ForeignKeyConstraintViolationException
	Assert::exception(function () use ($connection) {
		$connection->query('INSERT INTO `orders` (`customer_id`, `product_id`, `amount`) VALUES (99999 /*non-existing*/, 1, 7)');
	}, ForeignKeyConstraintViolationException::class);
};

// PDO error mode: warning
$runTests(buildDibiConnection(buildPDOConnection(PDO::ERRMODE_WARNING)));
