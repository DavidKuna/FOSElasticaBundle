<?php

/*
 * This file is part of the FOSElasticaBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\ElasticaBundle\Provider;

use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;

class Indexable implements IndexableInterface
{
    /**
     * An array of raw configured callbacks for all types.
     *
     * @var array
     */
    private $callbacks = [];

    /**
     * An instance of ExpressionLanguage.
     *
     * @var ExpressionLanguage
     */
    private $expressionLanguage;

    /**
     * An array of initialised callbacks.
     *
     * @var array
     */
    private $initialisedCallbacks = [];

    public function __construct(array $callbacks)
    {
        $this->callbacks = $callbacks;
    }

    /**
     * Return whether the object is indexable with respect to the callback.
     *
     * @param string $indexName
     * @param string $typeName
     * @param mixed  $object
     *
     * @return bool
     */
    public function isObjectIndexable($indexName, $typeName, $object)
    {
        $type = sprintf('%s/%s', $indexName, $typeName);
        $callback = $this->getCallback($type, $object);
        if (!$callback) {
            return true;
        }

        if ($callback instanceof Expression) {
            return (bool) $this->getExpressionLanguage()->evaluate($callback, [
                'object' => $object,
                $this->getExpressionVar($object) => $object,
            ]);
        }

        return is_string($callback)
            ? call_user_func([$object, $callback])
            : call_user_func($callback, $object);
    }

    /**
     * Builds and initialises a callback.
     *
     * @param string $type
     * @param object $object
     *
     * @return callable|string|ExpressionLanguage|null
     */
    private function buildCallback($type, $object)
    {
        if (!array_key_exists($type, $this->callbacks)) {
            return null;
        }

        $callback = $this->callbacks[$type];

        if (is_callable($callback) or is_callable([$object, $callback])) {
            return $callback;
        }

        if (is_string($callback)) {
            return $this->buildExpressionCallback($type, $object, $callback);
        }

        throw new \InvalidArgumentException(sprintf('Callback for type "%s" is not a valid callback.', $type));
    }

    /**
     * Processes a string expression into an Expression.
     *
     * @param string $type
     * @param mixed  $object
     * @param string $callback
     *
     * @return Expression
     */
    private function buildExpressionCallback($type, $object, $callback)
    {
        $expression = $this->getExpressionLanguage();
        if (!$expression) {
            throw new \RuntimeException('Unable to process an expression without the ExpressionLanguage component.');
        }

        try {
            $callback = new Expression($callback);
            $expression->compile($callback, [
                'object', $this->getExpressionVar($object),
            ]);

            return $callback;
        } catch (SyntaxError $e) {
            throw new \InvalidArgumentException(sprintf(
                'Callback for type "%s" is an invalid expression',
                $type
            ), $e->getCode(), $e);
        }
    }

    /**
     * Retreives a cached callback, or creates a new callback if one is not found.
     *
     * @param string $type
     * @param object $object
     *
     * @return mixed
     */
    private function getCallback($type, $object)
    {
        if (!array_key_exists($type, $this->initialisedCallbacks)) {
            $this->initialisedCallbacks[$type] = $this->buildCallback($type, $object);
        }

        return $this->initialisedCallbacks[$type];
    }

    /**
     * Returns the ExpressionLanguage class if it is available.
     *
     * @return ExpressionLanguage|null
     */
    private function getExpressionLanguage()
    {
        if (null === $this->expressionLanguage) {
            $this->expressionLanguage = new ExpressionLanguage();
        }

        return $this->expressionLanguage;
    }

    /**
     * Returns the variable name to be used to access the object when using the ExpressionLanguage
     * component to parse and evaluate an expression.
     *
     * @param mixed $object
     *
     * @return string
     */
    private function getExpressionVar($object = null)
    {
        if (!is_object($object)) {
            return 'object';
        }

        $ref = new \ReflectionClass($object);

        return strtolower($ref->getShortName());
    }
}
