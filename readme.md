# rudolf

**rudolf** is an HTTP API that wraps a SPARQL endpoint to expose [OpenBudgets.eu RDF-schema](https://github.com/openbudgets/data-model) - compatible datasets, according to OpenSpending's [babbage API](https://github.com/openspending/babbage) specification. **rudolf**'s name was chosen to remind the letters contained in the RDF acronym.

## Installation

**rudolf** is based on Laravel 5.2.
In order to install **rudolf**, you first have to setup a web server that supports the execution of PHP7 scripts. **rudolf** may also work with PHP >5.5.9, but it has not been yet tested with versions of PHP other than version 7.

The PHP extension `php_mbstring` is also required. You can install this by running `sudo apt-get install php-mbstring` in your debian-based operating system.

To support friendly URL's please make sure you have enabled `mod_rewrite` in your PHP installation.

After setting up your environment, you can install **rudolf**:
1. Clone this repository:

    git clone https://github.com/larjohn/rudolf.git 

2. Get into the newly created directory:

    `cd rudolf`

3. Run composer to install dependencies:

    `composer install --no-scripts`
## Configuration

After installing **rudolf**, change the configuration file located at `config/sparql.php` and set the appropriate endpoint URI.

## Command line operations
The structure of the datasets is dynamically discovered from the SPARQL endpoint the very first time a cube model is requested. The same goes for member values. They are then stored to the local cache.
 
Some operations may take long time in order to finish. There are some commands available to make life easier and avoid browser HTTP timeout:
* `model:clear {cubename}`: Clears a cube model
* `model:load {cubename}`: Loads a cube model
* `search:load`: Loads the whole search endpoint model

To run a command, `cd` to **rudolf**'s root folder and enter 
`php artisan {command} {parameters}`

For instance:
```php artisan model:load global```
loads the global cube

More commands are on the way! In the meantime, you can always use `cache:clear`.

## Official API Documentation

Documentation for the API can be found in the [official OpenSpending pages](http://docs.openspending.org/en/latest/developers/platform/).

## Roadmap
Current version:

* v0.1: Initial version - partial support of the babbage API

Next versions
* v0.2: Code cleanup, support hierarchical dimensions
* v0.3: Support cosmopolitan API equivalents

## Troubleshooting

If you are unable to acccess the API endpoint (e.g., rudolf/public/api/3/cubes path on your installation returns Not Found page), please check the following:

1. Your website directory (e.g. `\var\www\` is writable by www-data). Use `ls -l` to check the permission.

2. You may need to change your apache configuration in `/etc/apache2/sites-available/000-default.conf`. Within `<VirtualHost>` tag, provide the following lines:

	```
	<Directory /var/www/html>

	Options Indexes FollowSymLinks MultiViews
	AllowOverride All
	Order allow,deny
	allow from all

	</Directory>
	```
	
3. Restart Apache.	

## License

The **rudolf** API is open-source software licensed under the [MIT license](http://opensource.org/licenses/MIT). It is funded by by the OpenBudgets.eu Horizon 2020 project (Grant Agreement 645833). 
