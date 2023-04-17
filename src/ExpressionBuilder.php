<?php declare(strict_types=1);

namespace Somnambulist\Components\CTEBuilder;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder as DBALExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Somnambulist\Components\Collection\MutableCollection as Collection;
use Somnambulist\Components\CTEBuilder\Behaviours\CanPassThroughToQuery;
use Somnambulist\Components\CTEBuilder\Exceptions\ExpressionAlreadyExistsException;
use Somnambulist\Components\CTEBuilder\Exceptions\ExpressionNotFoundException;
use Somnambulist\Components\CTEBuilder\Exceptions\MissingExpressionAliasException;
use Somnambulist\Components\CTEBuilder\Exceptions\UnresolvableDependencyException;

/**
 * Aggregates and executes the Expressions as an SQL query. Requires that a query that
 * uses the CTEs be set.
 *
 * ExpressionBuilder (not to be confused with DBAL\Query\Expression\ExpressionBuilder) allows
 * method pass-through to the underlying primary query builder and any bound CTE can be
 * accessed using property accessors.
 *
 * @method ExpressionBuilder addGroupBy(string $groupBy)
 * @method ExpressionBuilder addOrderBy(string $sort, string $order = null)
 * @method ExpressionBuilder addSelect(string ...$select = null)
 * @method ExpressionBuilder andHaving($having)
 * @method ExpressionBuilder andWhere($where)
 * @method ExpressionBuilder createNamedParameter($value, int $type = ParameterType::STRING, string $placeHolder = null)
 * @method ExpressionBuilder createPositionalParameter($value, int $type = ParameterType::STRING)
 * @method ExpressionBuilder from(string $table, string $alias = null)
 * @method ExpressionBuilder groupBy($groupBy)
 * @method ExpressionBuilder having($having)
 * @method ExpressionBuilder innerJoin(string $fromAlias, string $join, string $alias, $conditions)
 * @method ExpressionBuilder join(string $fromAlias, string $join, string $alias, $conditions)
 * @method ExpressionBuilder leftJoin(string $fromAlias, string $join, string $alias, $conditions)
 * @method ExpressionBuilder orderBy(string $sort, string $order = null)
 * @method ExpressionBuilder orHaving($having)
 * @method ExpressionBuilder orWhere($where)
 * @method ExpressionBuilder rightJoin(string $fromAlias, string $join, string $alias, $conditions)
 * @method ExpressionBuilder select(string ...$field)
 * @method ExpressionBuilder setFirstResult(int $first)
 * @method ExpressionBuilder setMaxResults(int $max)
 * @method ExpressionBuilder setParameter(string|int $key, mixed $value, $type = null)
 * @method ExpressionBuilder setParameters(array $parameters)
 * @method ExpressionBuilder where($where)
 * @method DBALExpressionBuilder expr()
 */
class ExpressionBuilder
{
    use CanPassThroughToQuery;

    private Connection $conn;
    private QueryBuilder $query;
    private Collection $expressions;
    private Collection $parameters;
    private ?LoggerInterface $logger;

    public function __construct(Connection $conn, LoggerInterface $logger = null)
    {
        $this->conn        = $conn;
        $this->logger      = $logger;
        $this->query       = $conn->createQueryBuilder();
        $this->expressions = new Collection();
        $this->parameters  = new Collection();
    }

    public function __toString(): string
    {
        return $this->getSQL();
    }

    public function __get(string $name): mixed
    {
        if ($this->has($name)) {
            return $this->get($name);
        }

        throw new OutOfBoundsException(sprintf('CTE with alias "%s" has not been created', $name));
    }

    public function __clone()
    {
        $this->query       = clone $this->query;
        $this->parameters  = clone $this->parameters;
        $this->expressions = clone $this->expressions;
    }

    public function expressions(): Collection
    {
        return $this->expressions;
    }

    public function clear(): void
    {
        $this->query       = $this->conn->createQueryBuilder();
        $this->parameters  = new Collection();
        $this->expressions = new Collection();
    }

    public function query(): QueryBuilder
    {
        return $this->query;
    }

    public function createQuery(): QueryBuilder
    {
        return $this->conn->createQueryBuilder();
    }

    /**
     * Creates a new Expression that is not bound to the current builder instance
     *
     * @return Expression
     */
    public function createDetachedExpression(): Expression
    {
        return new Expression('', $this->createQuery());
    }

    /**
     * Create a new CTE Expression with optional required dependencies
     *
     * These dependencies are permanent and cannot be removed from the expression.
     *
     * @param string $alias
     * @param string ...$dependsOn A number of fixed dependent WITH expressions
     *
     * @return Expression
     */
    public function createExpression(string $alias, string ...$dependsOn): Expression
    {
        if ($this->has($alias)) {
            throw ExpressionAlreadyExistsException::aliasExists($alias);
        }

        return $this->with(new Expression($alias, $this->conn->createQueryBuilder(), $dependsOn))->get($alias);
    }

    /**
     * Create a new recursive Expression with optional required dependencies
     *
     * These dependencies are permanent and cannot be removed from the expression.
     *
     * @param string $alias
     * @param string ...$dependsOn A number of fixed dependent WITH expressions
     *
     * @return RecursiveExpression
     */
    public function createRecursiveExpression(string $alias, string ...$dependsOn): RecursiveExpression
    {
        if ($this->has($alias)) {
            throw ExpressionAlreadyExistsException::aliasExists($alias);
        }

        $this->with($cte = new RecursiveExpression($alias, $this->conn->createQueryBuilder(), $dependsOn));

        return $cte;
    }

    public function with(Expression $cte): self
    {
        if (!$cte->getAlias()) {
            throw MissingExpressionAliasException::new();
        }

        $this->expressions->set($cte->getAlias(), $cte);

        return $this;
    }

    public function get(string $alias): Expression
    {
        if (!$this->has($alias)) {
            throw ExpressionNotFoundException::aliasNotFound($alias);
        }

        return $this->expressions->get($alias);
    }

    public function has(string $alias): bool
    {
        return $this->expressions->has($alias);
    }

    public function getParameters(): Collection
    {
        $this->mergeParameters();

        return $this->parameters;
    }

    public function getParameter(string $param)
    {
        $this->mergeParameters();

        return $this->parameters->get($param);
    }

    public function hasParameter(string $param): bool
    {
        $this->mergeParameters();

        return $this->parameters->has($param);
    }

    private function mergeParameters(): void
    {
        $this->parameters->merge($this->query->getParameters());

        $this->expressions->each(fn(Expression $cte) => $this->parameters->merge($cte->getParameters()));
    }

    public function execute(): Result
    {
        $this->log();

        $stmt = $this->conn->prepare($this->getSQL());

        $this
            ->getParameters()
            ->each(fn($value, $key) => $stmt->bindValue($key, $value, (is_int($value) ? ParameterType::INTEGER : ParameterType::STRING)))
        ;

        if (method_exists($stmt, 'executeQuery')) {
            return $stmt->executeQuery();
        }

        return $stmt->execute();
    }

    public function getSQL(): string
    {
        return trim(sprintf('%s %s', $this->buildWith(), $this->query->getSQL()));
    }

    private function isRecursive(): bool
    {
        return $this->expressions->filter(fn(Expression $e) => $e instanceof RecursiveExpression)->count() > 0;
    }

    private function buildWith(): string
    {
        $with = $this
            ->buildDependencyTree($this->expressions)
            ->map(fn(Expression $cte, string $key) => $cte->getInlineSQL())
            ->implode(', ')
        ;

        return $with ? sprintf('WITH%s %s', $this->isRecursive() ? ' RECURSIVE' : '', $with) : '';
    }

    /**
     * @link https://stackoverflow.com/questions/39711720/php-order-array-based-on-elements-dependency
     */
    private function buildDependencyTree(Collection $ctes): Collection
    {
        $sortedExpressions    = new Collection();
        $resolvedDependencies = new Collection();

        while ($ctes->count() > $sortedExpressions->count()) {
            $resolvedDependenciesForCte = false;
            $alias                      = $dep = 'undefined';

            /**
             * @var string     $alias
             * @var Expression $cte
             */
            foreach ($ctes as $alias => $cte) {
                if ($resolvedDependencies->has($alias)) {
                    continue;
                }

                $resolved = true;

                foreach ($cte->getDependencies() as $dep) {
                    if (!is_null($test = $ctes->get($dep)) && in_array($alias, $test->getDependencies())) {
                        throw UnresolvableDependencyException::cyclicalDependency($alias, $dep);
                    }

                    if (!$resolvedDependencies->has($dep)) {
                        $resolved = false;
                        break;
                    }
                }

                if ($resolved) {
                    $resolvedDependencies->set($alias, true);
                    $sortedExpressions->add($cte);
                    $resolvedDependenciesForCte = true;
                }
            }

            if (!$resolvedDependenciesForCte) {
                throw UnresolvableDependencyException::cannotResolve($alias, $dep);
            }
        }

        return $sortedExpressions;
    }

    /**
     * Logs the compiled query to the standard logger as a debug message
     *
     * @codeCoverageIgnore
     */
    private function log(): void
    {
        if (!$this->logger) {
            return;
        }

        $this->logger->debug($this->expandQueryWithParameterSubstitution($this->getSQL(), $this->getParameters()));
    }

    /**
     * Returns a substituted compiled query for debugging purposes
     *
     * This is intended for debugging the build process and should not be used in production code.
     *
     * @param string     $query
     * @param Collection $parameters
     *
     * @return string
     * @internal
     * @codeCoverageIgnore
     */
    private function expandQueryWithParameterSubstitution(string $query, Collection $parameters): string
    {
        $debug = $parameters->map(function ($value) {
            return is_numeric($value) ? $value : $this->conn->quote((string)$value);
        });

        return strtr($query, $debug->toArray());
    }
}
