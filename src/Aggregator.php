<?php

/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\Aggregator;

use unreal4u\TelegramAPI\Telegram\Methods\SendMessage;
use unreal4u\TelegramAPI\TgLog;
use WildPHP\Core\Channels\Channel;
use WildPHP\Core\Commands\CommandHandler;
use WildPHP\Core\Commands\CommandHelp;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Connection\IRCMessages\PRIVMSG;
use WildPHP\Core\Connection\Queue;
use WildPHP\Core\Connection\TextFormatter;
use WildPHP\Core\ContainerTrait;
use WildPHP\Core\EventEmitter;
use WildPHP\Core\Modules\BaseModule;
use WildPHP\Core\Users\User;
use WildPHP\Modules\TGRelay\TGCommandHandler;

class Aggregator extends BaseModule
{
	use ContainerTrait;

	/**
	 * @var SourceDictionary
	 */
	protected $sourceDictionary = null;

	/**
	 * Aggregator constructor.
	 *
	 * @param ComponentContainer $container
	 */
	public function __construct(ComponentContainer $container)
	{
		// Register our command.
		$commandHelp = new CommandHelp();
		$commandHelp->append('Search for a keyword using an online source. Usage: find [source] [keyword or phrase]');
		CommandHandler::fromContainer($container)
			->registerCommand('find', [$this, 'findCommand'], $commandHelp, 2, -1);

		$commandHelp = new CommandHelp();
		$commandHelp->append('Lists all available data sources. No parameters.');
		CommandHandler::fromContainer($container)
			->registerCommand('lssources', [$this, 'lssourcesCommand'], $commandHelp, 0, 0);

		$this->setContainer($container);

		$sourceDictionary = new SourceDictionary($container);
		$this->setSourceDictionary($sourceDictionary);
		$sources = $sourceDictionary->findAllSources();
		$sourceDictionary->loadSources($sources);

		/** @var CommandHandler $commandHandler */
		$commandHandler = CommandHandler::fromContainer($container);
		$this->registerCommands($commandHandler, $sourceDictionary);

		EventEmitter::fromContainer($container)
			->on('telegram.commands.add', function (TGCommandHandler $commandHandler) use ($sourceDictionary)
			{
				$this->registerTGCommands($commandHandler, $sourceDictionary);
			});
	}

	/**
	 * @param CommandHandler $commandHandler
	 * @param SourceDictionary $pool
	 */
	public function registerCommands(CommandHandler $commandHandler, SourceDictionary $pool)
	{
		/** @var array<string,IAggregatorSource> $sources */
		$sources = $pool->getArrayCopy();

		foreach ($sources as $key => $source)
		{
			$commandHandler->registerCommand($key, [$this, 'handleResult'], null, 0, 3);
		}
	}

	/**
	 * @param TGCommandHandler $commandHandler
	 * @param SourceDictionary $pool
	 */
	public function registerTGCommands(TGCommandHandler $commandHandler, SourceDictionary $pool)
	{
		/** @var array<string,IAggregatorSource> $sources */
		$sources = $pool->getArrayCopy();

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

		$sourceDictionary = $this->getSourceDictionary();
		$sources = $sourceDictionary->getArrayCopy();

		$sourceStrings = [];
		/**
		 * @var string $key
		 * @var IAggregatorSource $source
		 */
		foreach ($sources as $key => $source)
		{
			$sourceStrings[] = $key . ' (' . $source->getReadableName() . ')';
		}

		Queue::fromContainer($container)
			->privmsg($originChannel, 'Available sources: ' . implode(', ', $sourceStrings));
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
		$sourceDictionary = $this->getSourceDictionary();
		$source = $sourceDictionary[$source];

		if (!$source || empty($args))
			return;

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
	 * @param TgLog $telegram
	 * @param mixed $chat_id
	 * @param array $arguments
	 * @param string $channel
	 * @param string $username
	 * @param string $source
	 */
	public function handleTelegramResult(TgLog $telegram, $chat_id, array $arguments, string $channel, string $username, string $source)
	{
		$sourceDictionary = $this->getSourceDictionary();
		$source = $sourceDictionary[$source];

		if (!$source || empty($arguments))
			return;

		$params = implode(' ', $arguments);
		$params = $this->parseParams($params);
		$results = $source->find($params['search']);

		if ($results === false)
		{
			$sendMessage = new SendMessage();
			$sendMessage->chat_id = $chat_id;
			$sendMessage->text = 'An error occurred while searching. Please try again later.';
			$telegram->performApiRequest($sendMessage);

			return;
		}
		elseif (empty($results))
		{
			$sendMessage = new SendMessage();
			$sendMessage->chat_id = $chat_id;
			$sendMessage->text = 'I had no matches for that query.';
			$telegram->performApiRequest($sendMessage);

			return;
		}
		$result = $this->getBestResult($params['search'], $results);

		if (empty($result))
			return;

		$string = $this->createSearchResultString($result);

		if (!empty($params['user']))
			$string = $params['user'] . ': ' . $string;

		if (empty($channel))
		{
			$sendMessage = new SendMessage();
			$sendMessage->chat_id = $chat_id;
			$sendMessage->text = $string;
			$telegram->performApiRequest($sendMessage);
		}

		if (!empty($channel))
		{
			$privmsg = new PRIVMSG($channel, '[TG] ' . TextFormatter::consistentStringColor($username) . ' searched for "' . $params['search'] . '". Result:');
			$privmsg->setMessageParameters(['relay_ignore']);
			Queue::fromContainer($this->getContainer())
				->insertMessage($privmsg);
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

		if (count($results) == 1)
			return array_shift($results);

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
	 * @param $sourceDictionary SourceDictionary
	 */
	public function setSourceDictionary(SourceDictionary $sourceDictionary)
	{
		$this->sourceDictionary = $sourceDictionary;
	}

	/**
	 * @return SourceDictionary
	 */
	public function getSourceDictionary(): SourceDictionary
	{
		return $this->sourceDictionary;
	}

	/**
	 * @return string
	 */
	public static function getSupportedVersionConstraint(): string
	{
		return '^3.0.0';
	}
}