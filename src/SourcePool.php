<?php

/*
	WildPHP - a modular and easily extendable IRC bot written in PHP
	Copyright (C) 2015 WildPHP

	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
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
	 * @return string[]
	 */
	public function getSourceKeys()
	{
		return array_keys($this->sources);
	}
}