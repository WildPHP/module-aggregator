# Aggregator Module
[![Build Status](https://scrutinizer-ci.com/g/WildPHP/module-aggregator/badges/build.png?b=master)](https://scrutinizer-ci.com/g/WildPHP/module-aggregator/build-status/master)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/WildPHP/module-aggregator/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/WildPHP/module-aggregator/?branch=master)
[![Latest Stable Version](https://poser.pugx.org/wildphp/module-aggregator/v/stable)](https://packagist.org/packages/wildphp/module-aggregator)
[![Latest Unstable Version](https://poser.pugx.org/wildphp/module-aggregator/v/unstable)](https://packagist.org/packages/wildphp/module-aggregator)
[![Total Downloads](https://poser.pugx.org/wildphp/module-aggregator/downloads)](https://packagist.org/packages/wildphp/module-aggregator)

Allows searching through various data sources.

## System Requirements
If your setup can run the main bot, it can run this module as well. You will need data sources to make use of this module.

## Installation
To install this module, we will use `composer`:

```composer require wildphp/module-aggregator```

That will install all required files for the module. In order to activate the module, add the following line to your modules array in `config.neon`:

    - WildPHP\Modules\Aggregator\Aggregator

The bot will run the module the next time it is started.

Additional sources can be added through composer as well. Refer to the source plugin installation instructions for more details.

## Configuration
In order for this module to work, you need at least the following in your `config.neon`:

```neon
aggregator:
	sources:
```

Additional sources may be added in that key in the following format:
```neon
		key: SourceClass
```

Example:
```neon
		wp: WildPHP\Modules\Aggregator\Sources\Wikipedia
```

The `key` value represents the keyword this source will use. Refer to the Usage section below for more details.

## Usage
By default, the `lssources` command is available. It simply lists all available and loaded sources by keyword.

All sources can be searched through using the `find` command. It has the following syntax:

```
find [source keyword] [search term] (@ [user])
```

Example:
```
find wp Atom @ MentionedUser
```

Or simply:
```
find wp Atom
```
to not have the output directed at someone.

Once you have configured the sources, they will be loaded per their keyword and a command is made available for them. For example, in the above example we set the Wikipedia source to the `wp` keyword. That means the `wp` command is now available.
The `wp` command will simply be an alias for `find wp`. That means it follows the same syntax as the `find` command but you do not have to specify a source.

## License
This module is licensed under the GNU General Public License, version 3. Please see `LICENSE` to read it.
