<?php

/*
 * This file is part of the League\Fractal package.
 *
 * (c) Phil Sturgeon <me@philsturgeon.uk>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace League\Fractal;

use League\Fractal\Resource\ResourceInterface;
use League\Fractal\Serializer\DataArraySerializer;
use League\Fractal\Serializer\SerializerAbstract;

/**
 * Manager
 *
 * Not a wildly creative name, but the manager is what a Fractal user will interact
 * with the most. The manager has various configurable options, and allows users
 * to create the "root scope" easily.
 */
class Manager
{
    /**
     * Array of scope identifiers for resources to include.
     *
     * @var array
     */
    protected $requestedIncludes = [];

    /**
     * Array of scope identifiers for resources to exclude.
     *
     * @var array
     */
    protected $requestedExcludes = [];

    /**
     * Array containing modifiers as keys and an array value of params.
     *
     * @var array
     */
    protected $includeParams = [];

    /**
     * The character used to separate modifier parameters.
     *
     * @var string
     */
    protected $paramDelimiter = '|';

    /**
     * Upper limit to how many levels of included data are allowed.
     *
     * @var int
     */
    protected $recursionLimit = 10;

    /**
     * Serializer.
     *
     * @var SerializerAbstract
     */
    protected $serializer;

    /**
     * Factory used to create new configured scopes.
     *
     * @var ScopeFactoryInterface
     */
    private $scopeFactory;

    /**
     * Create Data.
     *
     * Main method to kick this all off. Make a resource then pass it over, and use toArray()
     *
     * @param ResourceInterface $resource
     * @param string            $scopeIdentifier
     * @param Scope             $parentScopeInstance
     *
     * @return Scope
     */
    public function createData(ResourceInterface $resource, $scopeIdentifier = null, Scope $parentScopeInstance = null)
    {
        if ($parentScopeInstance !== null) {
            return $this->getScopeFactory()->createChildScopeFor($parentScopeInstance, $resource, $scopeIdentifier);
        }

        return $this->getScopeFactory()->createScopeFor($resource, $scopeIdentifier);
    }

    /**
     * Get Include Params.
     *
     * @param string $include
     *
     * @return \League\Fractal\ParamBag
     */
    public function getIncludeParams($include)
    {
        $params = isset($this->includeParams[$include]) ? $this->includeParams[$include] : [];

        return new ParamBag($params);
    }

    /**
     * Get Requested Includes.
     *
     * @return array
     */
    public function getRequestedIncludes()
    {
        return $this->requestedIncludes;
    }

    /**
     * Get Requested Excludes.
     *
     * @return array
     */
    public function getRequestedExcludes()
    {
        return $this->requestedExcludes;
    }

    /**
     * Get Serializer.
     *
     * @return SerializerAbstract
     */
    public function getSerializer()
    {
        if (! $this->serializer) {
            $this->setSerializer(new DataArraySerializer());
        }

        return $this->serializer;
    }

    /**
     * Parse Include String.
     *
     * @param array|string $includes Array or csv string of resources to include
     *
     * @return $this
     */
    public function parseIncludes($includes)
    {
        // Wipe these before we go again
        $this->requestedIncludes = $this->includeParams = [];

        if (is_string($includes)) {
            $includes = explode(',', $includes);
        }

        if (! is_array($includes)) {
            throw new \InvalidArgumentException(
                'The parseIncludes() method expects a string or an array. '.gettype($includes).' given'
            );
        }

        foreach ($includes as $include) {
            list($includeName, $allModifiersStr) = array_pad(explode(':', $include, 2), 2, null);

            // Trim it down to a cool level of recursion
            $includeName = $this->trimToAcceptableRecursionLevel($includeName);

            if (in_array($includeName, $this->requestedIncludes)) {
                continue;
            }
            $this->requestedIncludes[] = $includeName;

            // No Params? Bored
            if ($allModifiersStr === null) {
                continue;
            }

            // Matches multiple instances of 'something(foo|bar|baz)' in the string
            // I guess it ignores : so you could use anything, but probably don't do that
            preg_match_all('/([\w]+)(\(([^\)]+)\))?/', $allModifiersStr, $allModifiersArr);

            // [0] is full matched strings...
            $modifierCount = count($allModifiersArr[0]);

            $modifierArr = [];

            for ($modifierIt = 0; $modifierIt < $modifierCount; $modifierIt++) {
                // [1] is the modifier
                $modifierName = $allModifiersArr[1][$modifierIt];

                // and [3] is delimited params
                $modifierParamStr = $allModifiersArr[3][$modifierIt];

                // Make modifier array key with an array of params as the value
                $modifierArr[$modifierName] = explode($this->paramDelimiter, $modifierParamStr);
            }

            $this->includeParams[$includeName] = $modifierArr;
        }

        // This should be optional and public someday, but without it includes would never show up
        $this->autoIncludeParents();

        return $this;
    }

    /**
     * Parse Exclude String.
     *
     * @param array|string $excludes Array or csv string of resources to exclude
     *
     * @return $this
     */
    public function parseExcludes($excludes)
    {
        $this->requestedExcludes = [];

        if (is_string($excludes)) {
            $excludes = explode(',', $excludes);
        }

        if (! is_array($excludes)) {
            throw new \InvalidArgumentException(
                'The parseExcludes() method expects a string or an array. '.gettype($excludes).' given'
            );
        }

        foreach ($excludes as $excludeName) {
            $excludeName = $this->trimToAcceptableRecursionLevel($excludeName);

            if (in_array($excludeName, $this->requestedExcludes)) {
                continue;
            }

            $this->requestedExcludes[] = $excludeName;
        }

        return $this;
    }

    /**
     * Set Recursion Limit.
     *
     * @param int $recursionLimit
     *
     * @return $this
     */
    public function setRecursionLimit($recursionLimit)
    {
        $this->recursionLimit = $recursionLimit;

        return $this;
    }

    /**
     * Set Serializer
     *
     * @param SerializerAbstract $serializer
     *
     * @return $this
     */
    public function setSerializer(SerializerAbstract $serializer)
    {
        $this->serializer = $serializer;

        return $this;
    }

    /**
     * @return ScopeFactoryInterface
     */
    public function getScopeFactory()
    {
        if (!$this->scopeFactory) {
            $this->scopeFactory = new ScopeFactory($this);
        }

        return $this->scopeFactory;
    }

    /**
     * Set ScopeFactory
     *
     * @param ScopeFactoryInterface $scopeFactory
     *
     * @return $this
     */
    public function setScopeFactory(ScopeFactoryInterface $scopeFactory)
    {
        $this->scopeFactory = $scopeFactory;

        return $this;
    }

    /**
     * Auto-include Parents
     *
     * Look at the requested includes and automatically include the parents if they
     * are not explicitly requested. E.g: [foo, bar.baz] becomes [foo, bar, bar.baz]
     *
     * @internal
     *
     * @return void
     */
    protected function autoIncludeParents()
    {
        $parsed = [];

        foreach ($this->requestedIncludes as $include) {
            $nested = explode('.', $include);

            $part = array_shift($nested);
            $parsed[] = $part;

            while (count($nested) > 0) {
                $part .= '.'.array_shift($nested);
                $parsed[] = $part;
            }
        }

        $this->requestedIncludes = array_values(array_unique($parsed));
    }

    /**
     * Trim to Acceptable Recursion Level
     *
     * Strip off any requested resources that are too many levels deep, to avoid DiCaprio being chased
     * by trains or whatever the hell that movie was about.
     *
     * @internal
     *
     * @param string $includeName
     *
     * @return string
     */
    protected function trimToAcceptableRecursionLevel($includeName)
    {
        return implode('.', array_slice(explode('.', $includeName), 0, $this->recursionLimit));
    }
}
