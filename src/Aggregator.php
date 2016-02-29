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

use WildPHP\BaseModule;
use WildPHP\CoreModules\Connection\IrcDataObject;

class Aggregator extends BaseModule
{
	/**
	 * @var SourcePool
	 */
	 protected $sourcePool = null;

	/**
	 * Set up the module.
	 */
	public function setup()
	{
		// Register our command.
		$this->getEventEmitter()->on('irc.command.find', [$this, 'findCommand']);
		$this->getEventEmitter()->on('wildphp.init.after', [$this, 'initSourcePool']);
		$this->getEventEmitter()->on('irc.command.lssources', [$this, 'lsSourcesCommand']);
		$this->getEventEmitter()->on('irc.command', [$this, 'keywordListener']);

		include(__DIR__ . '/IAggregatorSource.php');
		include(__DIR__ . '/SourcePool.php');
		include(__DIR__ . '/SearchResult.php');

		include(__DIR__ . '/Sources/Wikipedia.php');
		include(__DIR__ . '/Sources/ArchWiki.php');
		include(__DIR__ . '/Sources/AUR.php');
		include(__DIR__ . '/Sources/ArchPkg.php');

		$sourcePool = new SourcePool($this);
		$this->setSourcePool($sourcePool);
	}

	public function initSourcePool()
	{
		$sourcePool = $this->getSourcePool();
		$sources = $sourcePool->findAllSources();
		$sourcePool->loadAllSources($sources);
	}

	/**
	 * @param $sourcePool SourcePool
	 */
	public function setSourcePool(SourcePool $sourcePool)
	{
		$this->sourcePool = $sourcePool;
	}

	/**
	 * @return SourcePool
	 */
	public function getSourcePool()
	{
		return $this->sourcePool;
	}

	/**
	 * The lssources command
	 * @param IrcDataObject $data The data received.
	 */
	public function lsSourcesCommand($command, $params, IrcDataObject $data)
	{
		$originChannel = $data->getTargets()[0];

		$sourcePool = $this->getSourcePool();
		$keys = $sourcePool->getSourceKeys();

		$this->replyToChannel($originChannel, 'Available sources: ' . implode(', ', $keys));
	}

	protected function handleResult($source, $search, $channel, $user = '')
	{
		$sourcePool = $this->getSourcePool();
		$source = $sourcePool->getSource($source);

		if (!$source)
		{
			$this->replyToChannel($channel, 'The specified source was not found.');
			return;
		}

		$results = $source->find($search);

		if (!$results)
		{
			$this->replyToChannel($channel, 'I had no search results for that query.');
			return;
		}
		$result = $this->getBestResult($search, $results);
		$string = $this->createSearchResultString($result);

		if (!empty($user))
			$string = $user . ': ' . $string;

		$this->replyToChannel($channel, $string);
	}

	/**
	 * The find command itself
	 * @param IrcDataObject $data The data received.
	 */
	public function findCommand($command, $params, IrcDataObject $data)
	{
		$originChannel = $data->getTargets()[0];

		$paramData = $this->parseFindCommandParams($params);

		if (!$paramData)
		{
			$this->replyToChannel($originChannel, 'Invalid parameters. Usage: find [source] [search terms] (@ [user])');
			return;
		}

		$this->handleResult($paramData['source'], $paramData['search'], $originChannel, $paramData['user']);
	}

	public function keywordListener($command, $params, IrcDataObject $data)
	{
		$sourcePool = $this->getSourcePool();
		$originChannel = $data->getTargets()[0];

		if (!$sourcePool->sourceKeyExists($command))
			return;

		$paramData = $this->parseParams($params);

		if (empty($params) || empty($paramData))
		{
			$this->replyToChannel($originChannel, 'You need to specify a search term. Usage: ' . $command . ' [search term] (@ [user])');
			return;
		}

		$this->handleResult($command, $paramData['search'], $originChannel, $paramData['user']);
	}

	public function getBestResult($comparedTo, $results)
	{
		$results = array_reverse($results);

		$bestResult = null;
		$shortestFound = -1;

		foreach ($results as $result)
		{
			$pkgname = $result->getTitle();

			$lev = levenshtein($comparedTo, $pkgname);

			// Exact match is best!
			if ($lev == 0)
			{
				$bestResult = $result;
				$shortestFound = 0;
				break;
			}

			// Closer to 0 is better!
			if ($lev <= $shortestFound || $shortestFound < 0)
			{
				$bestResult = $result;
				$shortestFound = $lev;
			}
		}
		return $bestResult;
	}

	/**
	 * @param SearchResult $searchResult
	 * @return string
	 */
	public function createSearchResultString(SearchResult $searchResult)
	{
		$str = $searchResult->getTitle();
		$str .= ' - ';

		if ($searchResult->getDescription())
		{
			$str .= $searchResult->getDescription();
			$str .= ' - ';
		}

		$str .= $searchResult->getUri();
		return $str;
	}

	/**
	 * @param string $params
	 * @return string[]|false
	 */
	public function parseFindCommandParams($params)
	{
		$regex = "/^(\\S+) (?:(.+) @ (\\S+)|(.+))$/";
		$params = trim($params);

		if (!preg_match($regex, $params, $matches))
			return false;

		$matches = array_values(array_filter($matches));

		return [
			'source' => $matches[1],
			'search' => $matches[2],
			'user' => !empty($matches[3]) ? $matches[3] : null
		];
	}

	public function parseParams($params)
	{
		$regex = "/^(?:(.+) @ (\\S+)|(.+))$/";
		$params = trim($params);

		if (!preg_match($regex, $params, $matches))
			return false;

		$matches = array_values(array_filter($matches));

		return [
			'search' => $matches[1],
			'user' => !empty($matches[2]) ? $matches[2] : null
		];
	}

	public function replyToChannel($channel, $message)
	{
		$connection = $this->getModule('Connection');
		$connection->write($connection->getGenerator()
			->ircPrivmsg($channel, $message));
	}
}
