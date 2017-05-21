<?php

/*
	WildPHP - a modular and easily extendable IRC bot written in PHP
	Copyright (C) 2016 WildPHP

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

namespace WildPHP\Modules\Aggregator\Sources;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use WildPHP\Modules\Aggregator\IAggregatorSource;
use WildPHP\Modules\Aggregator\SearchResult;

class Wikipedia implements IAggregatorSource
{
	protected $apiUri = 'https://en.wikipedia.org/w/api.php';

	protected function buildSearchUri($searchTerm, $limitResults = false)
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

	protected function getSearchResults($searchUri)
	{
		$client = new Client(['timeout' => 2.0]);
		$response = $client->get($searchUri);
		$contents = $response->getBody();
		$result = json_decode($contents);

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

	public function find($searchTerm)
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
}