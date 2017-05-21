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

use WildPHP\Core\Channels\Channel;
use WildPHP\Core\Commands\CommandHelp;
use WildPHP\Core\Commands\CommandHandler;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Connection\Queue;
use WildPHP\Core\ContainerTrait;
use WildPHP\Core\EventEmitter;
use WildPHP\Core\Users\User;

class Aggregator
{
	use ContainerTrait;

	/**
	 * @var SourcePool
	 */
	protected $sourcePool = null;

	public function __construct(ComponentContainer $container)
	{

		// Register our command.
		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Search for a keyword using an online source.');
		$commandHelp->addPage('Usage: find [source] [keyword or phrase]');
		CommandHandler::fromContainer($container)->registerCommand('find', [$this, 'findCommand'], $commandHelp);

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Lists all available data sources. No parameters.');
		CommandHandler::fromContainer($container)->registerCommand('lssources', [$this, 'lssourcesCommand'], $commandHelp);

		EventEmitter::fromContainer($container)->on('irc.command', [$this, 'keywordListener']);

		$this->setContainer($container);

		$sourcePool = new SourcePool($this);
		$this->setSourcePool($sourcePool);
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
	 * @param Channel $source
	 * @param User $user
	 * @param $args
	 * @param ComponentContainer $container
	 */
	public function lsSourcesCommand(Channel $source, User $user, $args, ComponentContainer $container)
	{
		$originChannel = $source->getName();

		$sourcePool = $this->getSourcePool();
		$keys = $sourcePool->getSourceKeys();

		Queue::fromContainer($container)->privmsg($originChannel, 'Available sources: ' . implode(', ', $keys));
	}

	/**
	 * @param string $source
	 * @param string $search
	 * @param string $channel
	 * @param string $user
	 * @param ComponentContainer $container
	 */
	protected function handleResult($source, $search, $channel, $user = '', ComponentContainer $container)
	{
		$sourcePool = $this->getSourcePool();
		$source = $sourcePool->getSource($source);

		if (!$source)
		{
			Queue::fromContainer($container)->privmsg($channel, 'The specified source was not found.');
			return;
		}

		$results = $source->find($search);

		if ($results === false)
		{
			Queue::fromContainer($container)->privmsg($channel, 'An error occurred while searching. Please try again later.');
			return;
		}
		elseif (empty($results))
		{
			Queue::fromContainer($container)->privmsg($channel, 'I had no results for that query.');
			return;
		}
		$result = $this->getBestResult($search, $results);
		$string = $this->createSearchResultString($result);

		if (!empty($user))
			$string = $user . ': ' . $string;

		Queue::fromContainer($container)->privmsg($channel, $string);
	}

	/**
	 * The find command itself
	 *
	 * @param Channel $source
	 * @param User $user
	 * @param $args
	 * @param ComponentContainer $container
	 */
	public function findCommand(Channel $source, User $user, $args, ComponentContainer $container)
	{
		$originChannel = $source->getName();

		$source = array_shift($args);

		$paramData = $this->parseFindCommandParams(implode(' ', $args));

		if (!$paramData)
		{
			Queue::fromContainer($container)->privmsg($originChannel,
				'Invalid parameters. Usage: find [source] [search terms] (@ [user])');
			return;
		}

		$this->handleResult(
			$source,
			$paramData['search'],
			$originChannel,
			$paramData['user'],
			$container
		);
	}

	/**
	 * @param string $command
	 * @param Channel $source
	 * @param User $user
	 * @param $args
	 * @param ComponentContainer $container
	 */
	public function keywordListener(string $command, Channel $source, User $user, $args, ComponentContainer $container)
	{
		$sourcePool = $this->getSourcePool();
		$originChannel = $source->getName();

		if (!$sourcePool->sourceKeyExists($command))
			return;

		$args = implode(' ', $args);
		$paramData = $this->parseParams($args);

		if (empty($paramData))
		{
			Queue::fromContainer($container)->privmsg($originChannel,
				'Invalid parameters. Usage: ' . $command . ' [search term] (@ [user])');
			return;
		}

		$this->handleResult(
			$command,
			$paramData['search'],
			$originChannel,
			$paramData['user'],
			$container
		);
	}

	/**
	 * @param $comparedTo
	 * @param $results
	 * @return null
	 */
	public function getBestResult($comparedTo, $results)
	{
		if (empty($results))
			return false;

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
		$regex = "/^(.+) @ (\\S+)|(.+)$/";
		$params = trim($params);

		if (!preg_match($regex, $params, $matches))
			return false;

		$matches = array_values(array_filter($matches));

		return [
			'search' => $matches[1],
			'user' => !empty($matches[2]) ? $matches[2] : null
		];
	}

	/**
	 * @param $params
	 * @return array|bool
	 */
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
}