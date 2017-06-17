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

namespace WildPHP\Modules\Aggregator\Sources;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use WildPHP\Modules\Aggregator\IAggregatorSource;
use WildPHP\Modules\Aggregator\SearchResult;

class AUR implements IAggregatorSource
{
	protected $apiUri = 'https://aur.archlinux.org/rpc/';
	protected $pkgBaseUri = 'https://aur.archlinux.org/packages/';

	/**
	 * @param array $parts
	 *
	 * @return array
	 */
	public function encodeUriParts(array $parts): array
	{
		$pieces = [];
		foreach ($parts as $key => $value)
		{
			$pieces[] = urlencode($key) . '=' . urlencode($value);
		}

		return $pieces;
	}

	/**
	 * @param array $parts
	 *
	 * @return string
	 */
	public function buildUriWithParts(array $parts = []): string
	{
		$uri = $this->apiUri . '?v=5';

		if (empty($parts))
			return $uri;

		$parts = $this->encodeUriParts($parts);
		$uri .= '&' . implode('&', $parts);

		return $uri;
	}

	/**
	 * @param string $uri
	 *
	 * @return false|SearchResult[]
	 */
	public function executeQuery(string $uri)
	{
		$client = new Client(['timeout' => 2.0]);
		$response = $client->get($uri);
		$contents = $response->getBody();
		$results = json_decode($contents, true);

		if (!$results || !array_key_exists('results', $results))
			return false;

		$finalresults = [];
		foreach ($results['results'] as $resultset)
		{
			$searchResult = new SearchResult();
			$pkgname = $resultset['Name'];
			$pkgver = $resultset['Version'];
			$pkgdesc = $resultset['Description'] . ' -- version ' . $pkgver;
			$pkguri = $this->pkgBaseUri . $resultset['PackageBase'];
			$title = $pkgname;

			$searchResult->setTitle($title);
			$searchResult->setDescription($pkgdesc);
			$searchResult->setUri($pkguri);
			$finalresults[] = $searchResult;
		}

		return $finalresults;
	}

	/**
	 * @param string $searchTerm
	 *
	 * @return false|SearchResult[]
	 */
	public function find(string $searchTerm)
	{
		$uri = $this->buildUriWithParts(['type' => 'search', 'arg' => $searchTerm]);

		try
		{
			$results = $this->executeQuery($uri);

			return $results;
		}
		catch (RequestException $e)
		{
			return false;
		}
	}
}
