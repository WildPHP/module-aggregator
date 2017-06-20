<?php

/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\Aggregator\Sources;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use WildPHP\Modules\Aggregator\IAggregatorSource;
use WildPHP\Modules\Aggregator\SearchResult;

class Wikipedia implements IAggregatorSource
{
	protected $apiUri = 'https://en.wikipedia.org/w/api.php';

	/**
	 * @param string $searchTerm
	 * @param int $limitResults
	 *
	 * @return string
	 */
	protected function buildSearchUri(string $searchTerm, int $limitResults = 0): string
	{
		$searchTerm = urlencode($searchTerm);
		$url = $this->apiUri;

		// use the OpenSearch API
		$url .= '?action=opensearch';

		// Don't use a namespace
		$url .= '&namespace=0';

		// Should we limit results?
		if (is_int($limitResults) && $limitResults >= 1)
			$url .= '&limit=' . $limitResults;

		// Return it in a JSON format and resolve redirects.
		$url .= '&format=json&redirects=resolve';

		// And of course the search term.
		$url .= '&search=' . $searchTerm;

		return $url;
	}

	/**
	 * @param $searchUri
	 *
	 * @return false|SearchResult[]
	 */
	protected function getSearchResults($searchUri)
	{
		$client = new Client(['timeout' => 2.0]);
		$response = $client->get($searchUri);
		$contents = $response->getBody();
		$result = json_decode($contents);

		if (!$result)
			return false;

		$results = [];
		// Because MediaWiki returns results in this awkward way,
		// we first process them into 'sane' results.
		foreach ($result[1] as $key => $title)
		{
			$searchResult = new searchResult();
			$searchResult->setTitle($title);
			$results[$key] = $searchResult;
		}

		foreach ($result[2] as $key => $description)
		{
			$results[$key]->setDescription($description);
		}

		foreach ($result[3] as $key => $uri)
		{
			$results[$key]->setUri($uri);
		}

		return $results;
	}

	/**
	 * @param string $searchTerm
	 *
	 * @return false|SearchResult[]
	 */
	public function find(string $searchTerm)
	{
		$uri = $this->buildSearchUri($searchTerm);

		try
		{
			$results = $this->getSearchResults($uri);
			return $results;
		}
		catch (RequestException $e)
		{
			return false;
		}
	}

	/**
	 * @return string
	 */
	public function getDescription(): string
	{
		return 'Search Wikipedia for a given string.';
	}

	/**
	 * @return string
	 */
	public function getReadableName(): string
	{
		return 'Wikipedia';
	}
}