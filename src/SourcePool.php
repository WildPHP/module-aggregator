<?php

/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\Aggregator;

use WildPHP\Core\Configuration\Configuration;
use WildPHP\Core\Logger\Logger;

class SourcePool
{
	/**
	 * 'key' => instance
	 * @var array
	 */
	protected $sources = [];

	/**
	 * @var string[]
	 */
	protected $loadedSources = [];

	/**
	 * @var Aggregator
	 */
	protected $parent = null;

	public function __construct(Aggregator $aggregator)
	{
		$this->parent = $aggregator;
	}

	/**
	 * @return string[] The keys of all found sources.
	 */
	public function findAllSources()
	{
		$allSources = Configuration::fromContainer($this->parent->getContainer())
			->get('aggregator.sources')
			->getValue();

		$validSources = [];
		foreach ($allSources as $key => $source)
		{
			if (!class_exists($source))
				continue;

			$validSources[$key] = $source;
		}

		return $validSources;
	}

	/**
	 * @param string[] $sources
	 *
	 * @return string[] The keys of all loaded sources.
	 */
	public function loadAllSources($sources = [])
	{
		$loadedSources = [];
		foreach ($sources as $key => $source)
		{
			Logger::fromContainer($this->parent->getContainer())
				->debug('[Aggregator] Added source', [
					'key' => $key,
					'class' => $source
				]);
			if ($this->loadSource($source, $key))
				$loadedSources[$key] = $source;
		}

		return $loadedSources;
	}

	/**
	 * @param string $source
	 *
	 * @return boolean
	 */
	public function isSourceLoaded($source)
	{
		return in_array($source, $this->loadedSources);
	}

	/**
	 * @param string $sourceKey
	 *
	 * @return boolean
	 */
	public function sourceKeyExists($sourceKey)
	{
		return array_key_exists($sourceKey, $this->sources);
	}

	/**
	 * @param string $source
	 * @param string $key
	 *
	 * @return boolean
	 */
	public function loadSource($source, $key)
	{
		if ($this->sourceKeyExists($key))
			return true;

		if (!class_exists($source) || empty($source) || empty($key))
			return false;

		$instance = new $source();

		if (!($instance instanceof IAggregatorSource))
			return false;

		$this->loadedSources[] = $source;
		$this->sources[$key] = $instance;

		return true;
	}

	/**
	 * @param string $sourceKey
	 *
	 * @return IAggregatorSource|false
	 */
	public function getSource($sourceKey)
	{
		if (!$this->sourceKeyExists($sourceKey))
			return false;

		return $this->sources[$sourceKey];
	}

	/**
	 * @return array<int|string>
	 */
	public function getSourceKeys()
	{
		return array_keys($this->sources);
	}

	/**
	 * @return array
	 */
	public function getLoadedSources()
	{
		return $this->sources;
	}
}