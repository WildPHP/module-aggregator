<?php

/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\Aggregator;

use Collections\Dictionary;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Configuration\Configuration;
use WildPHP\Core\ContainerTrait;
use WildPHP\Core\Logger\Logger;

class SourceDictionary extends Dictionary
{
	use ContainerTrait;

	/**
	 * SourceDictionary constructor.
	 *
	 * @param ComponentContainer $container
	 */
	public function __construct(ComponentContainer $container)
	{
		$this->setContainer($container);
		parent::__construct();
	}

	/**
	 * @return string[] The keys of all found sources.
	 */
	public function findAllSources()
	{
		$allSources = Configuration::fromContainer($this->getContainer())
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
	 * @return array<string,IAggregatorSource> The keys of all loaded sources.
	 */
	public function loadSources($sources = [])
	{
		$loadedSources = [];
		foreach ($sources as $key => $source)
		{
			if (!($source = $this->loadSource($source, $key)))
				continue;

			$this->offsetSet($key, $source);
			$loadedSources[$key] = $source;

			Logger::fromContainer($this->getContainer())
				->debug('[Aggregator] Added source', [
					'key' => $key,
					'class' => $source,
					'name' => $source->getReadableName(),
					'description' => $source->getDescription()
				]);
		}

		return $loadedSources;
	}

	/**
	 * @param string $source
	 * @param string $key
	 *
	 * @return boolean|IAggregatorSource
	 */
	public function loadSource($source, $key)
	{
		if ($this->offsetExists($key))
			return $this[$key];

		if (!class_exists($source) || empty($source) || empty($key))
			return false;

		$instance = new $source();

		if (!($instance instanceof IAggregatorSource))
			return false;

		return $instance;
	}
}