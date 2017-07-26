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

class UrbanDictionary implements IAggregatorSource
{
	protected $apiUri = 'https://api.urbandictionary.com/v0/';

	/**
	 * @param string $searchterm
	 *
	 * @return SearchResult[]|false
	 */
	protected function search(string $searchterm)
	{
		$uri = $this->apiUri . 'define?term=' . urlencode($searchterm);
		$client = new Client(['timeout' => 2.0]);
		$response = $client->get($uri);
		$contents = $response->getBody();
		$results = json_decode($contents, true);

		if ($results == false)
			return false;

		$topResult = null;
		$topResultNetto = 0;
		foreach ($results['list'] as $result)
		{
			$netto = $result['thumbs_up'] - $result['thumbs_down'];
			if ($netto < $topResultNetto)
				continue;

			$searchResult = new SearchResult();
			$searchResult->setTitle($result['word']);
			$searchResult->setDescription($result['definition']);
			$searchResult->setUri($result['permalink']);
			$topResult = $searchResult;
			$topResultNetto = $netto;
		}

		return [$topResult];
	}

	/**
	 * @param string $searchTerm
	 *
	 * @return false|SearchResult[]
	 */
	public function find(string $searchTerm)
	{
		try
		{
			$results = $this->search($searchTerm);

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
		return 'Define a word using Urban Dictionary';
	}

	/**
	 * @return string
	 */
	public function getReadableName(): string
	{
		return 'Urban Dictionary';
	}
}