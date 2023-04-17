# Common Table Expression Builder

[![GitHub Actions Build Status](https://img.shields.io/github/actions/workflow/status/somnambulist-tech/cte-builder/tests.yml?logo=github&branch=master)](https://github.com/somnambulist-tech/cte-builder/actions?query=workflow%3Atests)
[![Issues](https://img.shields.io/github/issues/somnambulist-tech/cte-builder?logo=github)](https://github.com/somnambulist-tech/cte-builder/issues)
[![License](https://img.shields.io/github/license/somnambulist-tech/cte-builder?logo=github)](https://github.com/somnambulist-tech/cte-builder/blob/master/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/somnambulist/cte-builder?logo=php&logoColor=white)](https://packagist.org/packages/somnambulist/cte-builder)
[![Current Version](https://img.shields.io/packagist/v/somnambulist/cte-builder?logo=packagist&logoColor=white)](https://packagist.org/packages/somnambulist/cte-builder)

Provides a programmatic interface via Doctrine DBAL Query Builder for creating Common
Table Expressions (WITH clauses). Includes support for CTE dependencies and debugging.
A CTE allows extracting a sub-select or derived query that can be executed separately
to the main query, but then referenced again. Depending on the database server being
used, there may be significant performance advantages to this approach, for example:
in Postgres a CTE is evaluated once and the same result used no matter how many times
it is referenced.

CTEs can also be used to pre-generate content that is needed multiple times, ensuring
that any significant computational cost is only incurred once.

Be sure to read up on CTEs and WITH clauses for your chosen database server.

## Requirements

 * PHP 8.0+
 * doctrine/dbal
 * somnambulist/collection

## Installation

Install using composer, or checkout / pull the files from github.com.

 * composer require somnambulist/cte-builder

__Note:__ 3.2.0 changes the return type on the main execute method for compatibility with doctrine/dbal.
This method now returns the `Doctrine\DBAL\Result` object and not a `Statement` object.

## Usage

CTE Builder consists of the ExpressionBuilder and the Expression. Expressions are created
either directly and bound to the builder, or via the builder. The builder requires:

 * DBAL Connection

If using Symfony the default configured connection can be used.

```php
<?php
use Somnambulist\Components\CTEBuilder\ExpressionBuilder;

$eb = new ExpressionBuilder($connection);
$expr = $eb->createExpression('first_clause');

$result = $eb->select('field', 'another field')->from('table_or_cte')->execute();
```

Each expression is its own independent query builder instance using the same connection.
Each CTE can be as complex as required.

CTEs can reference other CTEs. When creating the query, use the CTE alias. It helps to use
constants for the aliases to make it easier to keep them in-sync. When referencing other
CTEs it is important to then set that as an explicit dependency of the CTE:

```php
<?php
use Somnambulist\Components\CTEBuilder\ExpressionBuilder;

$eb = new ExpressionBuilder($connection);
$expr1 = $eb->createExpression('first_clause');
$expr1->from('second_clause');
$expr2 = $eb->createExpression('second_clause');

$expr1->dependsOn('second_clause');
```

It is very important to keep track of your dependencies as CTEs must be defined before
they are referenced - they cannot be back-referenced; hence the need to set the dependency.

Alternatively, specify the dependencies when creating the expression:

```php
<?php
use Somnambulist\Components\CTEBuilder\ExpressionBuilder;

$eb = new ExpressionBuilder($connection);
$expr1 = $eb->createExpression('first_clause', 'second_clause');
$expr1->from('second_clause');
```

__Note:__ if dependencies are specified at creation they cannot be undone i.e. they are
permanently bound to the expression.

Both the ExpressionBuilder and Expression expose `query()` for accessing the QueryBuilder
directly. Parameters can be bound to both and will be automatically merged together in the
ExpressionBuilder as needed.

__Note:__ because the CTEs can be re-ordered and all parameters must be collected together
and passed to the compiled query named-placeholders __MUST__ be used. If positional
placeholders are used, the query will almost certainly fail.

Once defined a CTE can be accessed from the ExpressionBuilder either via the `get()` method
or via a dynamic property accessor:

```php
<?php
use Somnambulist\Components\CTEBuilder\ExpressionBuilder;

$eb = new ExpressionBuilder($connection);
$eb->createExpression('first_clause');
$eb->createExpression('second_clause');
$eb->createExpression('third_clause');

$eb->third_clause->select();
```

### UNION Queries in CTE expressions

From v3.3 there is very basic support for UNION / UNION ALL when using Expression objects. This allows more
easily creating UNIONs on the CTE expressions.

__Note:__ this is very limited and does not support wrapped statements / ORDER BY on the expressions used
in UNIONs. Attempts to add ORDER BY to an expression passed to `union()`, `unionAll()`, or `addUnion()`
will result in an exception being thrown at query compile time.

To build a union query for a CTE expression, first create the holder expression via `createExpression()`
and then call `union()` or `unionAll()` with the expressions to join together. Multiple expressions can
be passed as separate arguments, or a single expression can be added via `addUnion()`. Note that both
`union()` and `unionAll()` will reset the store of union expressions.

For example:

```php
<?php
use Somnambulist\Components\CTEBuilder\ExpressionBuilder;

$eb = new ExpressionBuilder($connection);
$expr = $eb->createExpression('unioned_data');
$expr->select('field1', 'field2')->from('some_table');

$otherData = $eb->createDetachedExpression();
$otherData->select('id AS field1', 'name AS field2')->from('some_other_table');
$otherData2 = $eb->createDetachedExpression();
$otherData2->select('id AS field1', 'name AS field2')->from('some_other_table');

$expr->union($otherData, $otherData2);
//$expr->unionAll($otherData, $otherData2);
```

Or add the unions using `addUnion()`:

```php
$expr->addUnion($otherData)->addUnion($otherData2);
```

__Note:__ you should not use the union methods with recursive CTEs. This will cause unpredictable
behaviour and/or execution errors.

### Recursive CTEs

To create a recursive CTE, first create the builder as before and then use `createRecursiveExpression`.
This will return a `RecursiveExpression` instance. It is largely the same as the standard `Expression`
except it provides the additional methods:

 * `withInitialSelect`
 * `withUniqueRows`

`withInitialSelect` is used to initialise the carry that is used in the following recursive call. This
can be simple value e.g.: `VALUES(1)` or `SELECT 1` or a more complex query / query builder instance.
If using a query builder instance any parameters __MUST__ be named parameters. The parameters will be
merged into the CTE and the SQL cast to a string.

`withUniqueRows` (default false) if set to `true` will change the UNION ALL to a UNION.

Finally: the standard `query()` is for setting up the recursive query itself i.e: the right side of
the UNION.

Recursive expressions support the same dependencies and calls as Expressions (they inherit all methods).

__Note:__ if your main query requires further `UNION` statements, then you will need to force the
query into the SELECT clause as the underlying DBAL QueryBuilder does not support UNION queries.

__Note:__ as the initial select clause could have no column names, you must specify the names of the
fields that will be returned by calling: `withFields()` and then supplying a list of fields.

See the test cases for some examples of simple queries and then a more complex case adapted from the
SQlite documentation.

### Paginating

An adapter is included for Pagerfanta to handle pagination of results. To use it:
`composer req pagerfanta/pagerfanta`, and then create your CTE as normal. To add paginated results,
consider this contrived example:

```php
$cte = new ExpressionBuilder($conn);
// this is just as example, this is a poor use of CTEs
$users = $cte->createExpression('only_users');
$users->select('*')->from('users')->where('type = :type')->setParameter('type', 'user');

$cte->select('*')->from('only_users');

$paginator = new PagerfantaAdapter($cte, function (ExpressionBuilder $qb) {
    $qb->select('COUNT(*) AS total_results');
});
$pf = new Pagerfanta($paginator);
$pf->setMaxPerPage(1)->setCurrentPage(3);

foreach ($pf as $result) {
    dump($result);
}
```

The paginator adapter will clone the `ExpressionBuilder` to apply whatever counting mechanic you need
without affecting the main query.

## Profiling

If you use Symfony; using the standard Doctrine DBAL connection from your entity manager will
automatically ensure that the main SQL query is automatically profiled. However: the fully
compiled query with substituted parameters can be dumped by passing in a logger instance. The
query will be logged using debug. This should be done when testing / debugging building a
complex query.

For further insights consider using an application profiler such as:

 * [Tideways](https://tideways.io)
 * [BlackFire](https://blackfire.io)

For other frameworks; as DBAL is used, hook into the Configuration object and add an SQL
logger instance that can report to your frameworks' profiler.

## Test Suite

Run the test suite via: `vendor/bin/phpunit`.
