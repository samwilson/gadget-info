<?php

namespace Samwilson\GadgetInfo\Command;

use SimpleXMLElement;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DomCrawler\Crawler;

class Run extends Command {

	/** @var SymfonyStyle */
	protected $io;

	/**
	 * Configures the current command.
	 */
	protected function configure()
	{
		$this->setName('run');
		$this->setDescription('Run around in circles');
	}

	/**
	 * Executes the current command.
	 *
	 * @return null|int null or 0 if everything went fine, or an error code
	 *
	 * @see setCode()
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->io = new SymfonyStyle($input, $output);

		$this->io->write('Getting list of wikis . . . ');
		$sparql = "
		SELECT ?website WHERE {
		  {
			?item wdt:P31* wd:Q33120867 .
			?item wdt:P856 ?website
		  }
		  union
		  {
			?WikimediaWiki wdt:P279* wd:Q33120867 .
			?item wdt:P31 ?WikimediaWiki .
			?item wdt:P856 ?website
		  }
		}
		";
		$url = "https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=" . urlencode( $sparql );
		$result = file_get_contents( $url );
		$xml = new SimpleXmlElement( $result );
		$wikiUrls = [];
		foreach ( $xml->results->result as $res ) {
			foreach ( $res->binding as $binding ) {
				if ( isset( $binding->uri ) ) {
					$wikiUrls[] = (string)$binding->uri;
				}
			}
		}
		$total = count($wikiUrls);
		$this->io->writeln( "$total found" );

		// Get CSV file ready.
		$csvFile = fopen( __DIR__ . '/../../var/data.csv', 'w' );
		fputcsv( $csvFile, [ 'wiki', 'gadget', 'users_total', 'users_active', 'default' ] );

		foreach ( $wikiUrls as $num => $url ) {
			$this->io->writeln( sprintf( '%4d/%d %s', $num + 1, $total, $url ) );
			$this->oneWiki( $url, $csvFile );
		}
	}

	protected function oneWiki($url, $csvFile)
	{
		$usageUrl = $url.'wiki/Special:GadgetUsage';
		$pageHtml = file_get_contents( $usageUrl );
		$pageCrawler = new Crawler();
		// Note the slightly odd way of ensuring the HTML content is loaded as UTF8.
		$pageCrawler->addHtmlContent( $pageHtml, 'UTF-8' );
		$table = $pageCrawler->filterXPath("//table[contains(@class, 'wikitable')]//tr");
		if ($table->count() === 0) {
			$this->io->writeln(  "Nothing found for $usageUrl" );
			return;
		}
		foreach ($table as $row) {
			if ($row->childNodes[0]->tagName === 'th') {
				// Ignore table headers.
				continue;
			}
			$gadgetName = $row->childNodes[0]->nodeValue;
			$usersTotal = filter_var( $row->childNodes[1]->nodeValue, FILTER_SANITIZE_NUMBER_INT );
			if ( is_numeric( $usersTotal ) && (int)$usersTotal > 0 ) {
				$usersActive = isset( $row->childNodes[2]->nodeValue )
					? filter_var( $row->childNodes[2]->nodeValue, FILTER_SANITIZE_NUMBER_INT )
					: '';
				$default = 0;
			} else {
				$usersTotal = '';
				$usersActive = '';
				$default = 1;
			}
			$gadgetInfo = [
				'wiki' => $url,
				'gadget' => $gadgetName,
				'users_total' => $usersTotal,
				'users_active' => $usersActive,
				'default' => $default,
			];
			fputcsv( $csvFile, $gadgetInfo );
		}
	}
}
