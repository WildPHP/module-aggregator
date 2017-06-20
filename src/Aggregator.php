<?php

/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\Aggregator;

use phpDocumentor\Reflection\DocBlock\Tags\Source;
use WildPHP\Core\Channels\Channel;
use WildPHP\Core\Commands\CommandHelp;
use WildPHP\Core\Commands\CommandHandler;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Connection\Queue;
use WildPHP\Core\ContainerTrait;
use WildPHP\Core\EventEmitter;
use WildPHP\Core\Users\User;
use WildPHP\Modules\TGRelay\TGCommandHandler;

class Aggregator
{
	use ContainerTrait;

	/**
	 * @var SourcePool
	 */
	protected $sourcePool = null;

	/**
	 * Aggregator constructor.
	 *
	 * @param ComponentContainer $container
	 */
	public function __construct(ComponentContainer $container)
	{
		// Register our command.
		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Search for a keyword using an online source. Usage: find [source] [keyword or phrase]');
		CommandHandler::fromContainer($container)
			->registerCommand('find', [$this, 'findCommand'], $commandHelp, 2, -1);

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Lists all available data sources. No parameters.');
		CommandHandler::fromContainer($container)
			->registerCommand('lssources', [$this, 'lssourcesCommand'], $commandHelp, 0, 0);

		$this->setContainer($container);

		$sourcePool = new SourcePool($this);
		$this->setSourcePool($sourcePool);
		$sourcePool = $this->getSourcePool();
		$sources = $sourcePool->findAllSources();
		$sourcePool->loadAllSources($sources);

		/** @var CommandHandler $commandHandler */
		$commandHandler = CommandHandler::fromContainer($container);
		$this->registerCommands($commandHandler, $sourcePool);

		EventEmitter::fromContainer($container)->on('telegram.commands.add', function (TGCommandHandler $commandHandler) use ($sourcePool)
		{
			$this->registerTGCommands($commandHandler, $sourcePool);
		});
	}

	/**
	 * @param CommandHandler $commandHandler
	 * @param SourcePool $pool
	 */
	public function registerCommands(CommandHandler $commandHandler, SourcePool $pool)
	{
		/** @var array<string,IAggregatorSource> $sources */
		$sources = $pool->getLoadedSources();

		foreach ($sources as $key => $source)
		{
			$commandHandler->registerCommand($key, [$this, 'handleResult'], null, 0, 3);
		}
	}

	/**
	 * @param TGCommandHandler $commandHandler
	 * @param SourcePool $pool
	 */
	public function registerTGCommands(TGCommandHandler $commandHandler, SourcePool $pool)
	{
		/** @var array<string,IAggregatorSource> $sources */
		$sources = $pool->getLoadedSources();

		foreach ($sources as $key => $source)
		{
			$commandHandler->registerCommand($key, [$this, 'handleTelegramResult'], null, 0, 3);
		}
	}

	/**
	 * @param Channel $source
	 * @param User $user
	 * @param array $args
	 * @param ComponentContainer $container
	 */
	public function lsSourcesCommand(Channel $source, User $user, array $args, ComponentContainer $container)
	{
		$originChannel = $source->getName();

		$sourcePool = $this->getSourcePool();
		$keys = $sourcePool->getSourceKeys();

		Queue::fromContainer($container)
			->privmsg($originChannel, 'Available sources: ' . implode(', ', $keys));
	}

	/**
	 * @param Channel $channel
	 * @param User $user
	 * @param array $args
	 * @param ComponentContainer $container
	 * @param string $source
	 */
	public function handleResult(Channel $channel, User $user, array $args, ComponentContainer $container, string $source)
	{
		$sourcePool = $this->getSourcePool();
		$source = $sourcePool->getSource($source);

		$params = implode(' ', $args);
		$params = $this->parseParams($params);
		$search = $params['search'];

		$results = $source->find($search);

		if ($results === false)
		{
			Queue::fromContainer($container)
				->privmsg($channel->getName(), 'An error occurred while searching. Please try again later.');

			return;
		}
		elseif (empty($results))
		{
			Queue::fromContainer($container)
				->privmsg($channel->getName(), 'I had no results for that query.');

			return;
		}

		// No need to check for null here. $results was just checked for emptiness.
		$result = $this->getBestResult($search, $results);
		$string = $this->createSearchResultString($result);

		if (!empty($params['user']))
			$string = $params['user'] . ': ' . $string;

		Queue::fromContainer($container)
			->privmsg($channel->getName(), $string);
	}

	/**
	 * @param string $source
	 * @param \Telegram $telegram
	 * @param mixed $chat_id
	 * @param array $arguments
	 * @param string $channel
	 * @param string $username
	 */
	public function handleTelegramResult(\Telegram $telegram, $chat_id, array $arguments, string $channel, string $username, string $source)
	{
		$sourcePool = $this->getSourcePool();
		$source = $sourcePool->getSource($source);

		if (!$source)
			return;

		$params = implode(' ', $arguments);
		$params = $this->parseParams($params);
		$results = $source->find($params['search']);

		if ($results === false)
		{
			$telegram->sendMessage(['chat_id' => $chat_id, 'text' => 'An error occurred while searching. Please try again later.']);

			return;
		}
		elseif (empty($results))
		{
			$telegram->sendMessage(['chat_id' => $chat_id, 'text' => 'I had no results for that query.']);

			return;
		}
		$result = $this->getBestResult($params['search'], $results);
		$string = $this->createSearchResultString($result);

		if (!empty($params['user']))
			$string = $params['user'] . ': ' . $string;

		$telegram->sendMessage(['chat_id' => $chat_id, 'text' => $string]);

		if (!empty($channel))
		{
			Queue::fromContainer($this->getContainer())
				->privmsg($channel, '[TG] ' . $username . ' searched for "' . $params['search'] . '". Result:');
			Queue::fromContainer($this->getContainer())
				->privmsg($channel, $string);
		}
	}

	/**
	 * @param Channel $channel
	 * @param User $user
	 * @param array $args
	 * @param ComponentContainer $container
	 */
	public function findCommand(Channel $channel, User $user, array $args, ComponentContainer $container)
	{
		$source = array_shift($args);

		$this->handleResult(
			$channel,
			$user,
			$args,
			$container,
			$source
		);
	}

	/**
	 * @param string $comparedTo
	 * @param SearchResult[] $results
	 *
	 * @return null|SearchResult
	 */
	public function getBestResult(string $comparedTo, array $results)
	{
		if (empty($results))
			return null;

		$results = array_reverse($results);

		$bestResult = null;
		$shortestFound = -1;

		/** @var SearchResult $result */
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
	 *
	 * @return string
	 */
	public function createSearchResultString(SearchResult $searchResult): string
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
	 * @param $params
	 *
	 * @return false|array
	 */
	public function parseParams($params)
	{
		$regex = '/^(?:(.+) @ (\\S+)|(.+))$/';
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
	 * @param $sourcePool SourcePool
	 */
	public function setSourcePool(SourcePool $sourcePool)
	{
		$this->sourcePool = $sourcePool;
	}

	/**
	 * @return SourcePool
	 */
	public function getSourcePool(): SourcePool
	{
		return $this->sourcePool;
	}
}