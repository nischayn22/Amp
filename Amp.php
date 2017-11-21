<?php

class Amp {

	public static function onParserSetup( Parser $parser ) {
		$parser->setHook( 'amp', 'Amp::addAmpLink' );
	}

	public static function addAmpLink( $input, array $args, Parser $parser, PPFrame $frame ) {
		global $wgOut, $wgScriptPath;
		$wgOut->addLink(
			array(
				"rel" => "amphtml",
				"href" => $wgScriptPath . "/extensions/Amp/ampfiles/" . str_replace(" ", "_", $parser->getTitle()->getFullText()) . ".html",
			)
		);
		return htmlspecialchars( $input );
	}

	public static function generateAmpHtml( $output )
	{
		$isAmpPage = false;
		foreach($output->getLinkTags() as $linkTag) {
			if ($linkTag['rel'] == "amphtml") {
				$isAmpPage = true;
			}
		}
		if (!$isAmpPage) {
			return true;
		}

		$filepath = str_replace(" ", "_", $output->getTitle()->getFullText() . ".html");

		if (file_exists(__DIR__ . '/ampfiles/' . $filepath)) {
			if (wfTimestampNow() - wfTimestamp( TS_MW, $output->getRevisionTimestamp()) > 60) {
				return true;
			}
		}

		global $wgServer, $wgSitename, $wgLogo, $wgGoogleAnalyticsAccount, $wgSiteTagline, $ampFooterLinks;

		// Begin with a Template
		$dom = new DOMDocument();
		@$dom->loadHTMLFile(__DIR__ . '/AmpTemplate.html' );

		// Set Home Link
		$finder = new DomXPath($dom);
		$home_links = $finder->query("//*[contains(@class, 'home_link')]");
		foreach($home_links as $home_link) {
			$home_link->setAttribute("href", $wgServer);
			$home_link->nodeValue = $wgSitename;
		}

		// Set Tagline
		$site_taglines = $finder->query("//*[contains(@class, 'site_tagline')]");
		foreach($site_taglines as $site_tagline) {
			$site_tagline->nodeValue = $wgSiteTagline;
		}

		// Setting Styles
		$dom->getElementsByTagName('style')->item(0)->nodeValue = file_get_contents(__DIR__ . '/style.css');
		$dom->getElementsByTagName('style')->item(0)->nodeValue .= ".top-bar .name h1 a{background-image:url(" . $wgLogo . ");background-repeat:no-repeat;height:59px;width:260px;font-size:0px;}";
		
		// Set Canonical Link
		$dom->getElementsByTagName('link')->item(0)->setAttribute("href", $output->getTitle()->getFullURL());

		// Set Title
		$dom->getElementsByTagName('title')->item(0)->nodeValue = $output->getHTMLTitle();

		// Set Footer Links
		$footer_leftgrid = $dom->getElementById('footer_leftgrid');
		foreach($ampFooterLinks['left'] as $footer_link) {
			$link = '';
			if (array_key_exists('href', $footer_link)) {
				$link = $dom->createElement('a');
				$link->setAttribute('href', $footer_link['href']);
				$link->setAttribute('title', $footer_link['title']);
				$link->nodeValue = $footer_link['content'];
			} else {
				$link = $dom->createElement('b');
				$link->nodeValue = $footer_link['content'];
			}
			$link_li = $dom->createElement('li');
			$link_li->appendChild($link);
			$footer_leftgrid->appendChild($link_li);
		}

		$footer_rightgrid = $dom->getElementById('footer_rightgrid');
		foreach($ampFooterLinks['right'] as $footer_link) {
			$link = '';
			if (array_key_exists('href', $footer_link)) {
				$link = $dom->createElement('a');
				$link->setAttribute('href', $footer_link['href']);
				$link->setAttribute('title', $footer_link['title']);
				$link->nodeValue = $footer_link['content'];
			} else {
				$link = $dom->createElement('b');
				$link->nodeValue = $footer_link['content'];
			}
			$link_li = $dom->createElement('li');
			$link_li->appendChild($link);
			$footer_rightgrid->appendChild($link_li);
		}


		// Set Text from this page
		$new_body = new DOMDocument();
		$new_body->loadHtml($output->getHTML());
		$new_body = $new_body->getElementsByTagName('body')->item(0);
		$mw_content_text = $dom->getElementById('mw-content-text');
		foreach($new_body->childNodes as $childNode) {
			$mw_content_text->appendChild($dom->importNode($childNode, true));
		}

		// Remove unsupported/unecessary stuff
		$dit = new RecursiveIteratorIterator(
			new RecursiveDOMIterator($dom->getElementsByTagName('body')->item(0)),
			RecursiveIteratorIterator::SELF_FIRST
		);
		$nodes_to_be_deleted = array();
		foreach($dit as $node) {
			if($node->nodeType === XML_ELEMENT_NODE) {
				if (
					!in_array(
						$node->nodeName,
						array(
							"meta", "div", "img", "form", "footer", "button", "p", "b", "i", "span", "a", "ul", "li", "h1", "h2", "h3", "h4", "h5", "nav", "br", "table", "th", "th", "tr", "td", "script"
						)
					)
				) {
					$nodes_to_be_deleted[] = $node;
				} else {
					if ($node->hasAttribute('style')) {
						$node->removeAttribute('style');
					}
					if ($node->nodeName == 'img') {
						$amp_img = $dom->createElement('amp-img');
						$src = $node->getAttribute('src');
						if ($node->getAttribute('data-original') != '') {
							$src = $node->getAttribute('data-original');
						}
						list($width, $height, $type, $attr) = getimagesize($src);
						$amp_img->setAttribute('src', $src);
						$amp_img->setAttribute('width', $width);
						$amp_img->setAttribute('height', $height);
						$amp_img->setAttribute('layout', 'responsive');
						$node->parentNode->replaceChild($amp_img, $node);
					}
					if ($node->nodeName == 'script' && $node->getAttribute('type') != "application/ld+json") {
						$nodes_to_be_deleted[] = $node;
					}
				}
			}
		}
		foreach($nodes_to_be_deleted as $node) {
			$node->parentNode->removeChild($node);
		}

		if (!empty($wgGoogleAnalyticsAccount)) {
			$google_analytics = new DOMDocument();
			$google_analytics->loadHtml('<amp-analytics type="googleanalytics"><script type="application/json">{  "vars": {    "account": "'. $wgGoogleAnalyticsAccount .'"  },  "triggers": {    "trackPageview": {      "on": "visible",      "request": "pageview"    }  }}</script></amp-analytics>');

			foreach($google_analytics->getElementsByTagName('body')->item(0)->childNodes as $childNode) {
				$dom->getElementsByTagName('body')->item(0)->appendChild($dom->importNode($childNode, true));
			}
		}

		if (!is_dir( __DIR__ . '/ampfiles')) {
			mkdir( __DIR__ . '/ampfiles');
		}
		file_force_contents($filepath, $dom->saveHTML());
	}
}

function file_force_contents($dir, $contents){
	$parts = explode('/', $dir);
	$file = array_pop($parts);
	$dir = __DIR__ . '/ampfiles';
	foreach($parts as $part)
		if(!is_dir($dir .= "/$part")) {
			mkdir($dir);
		}
	file_put_contents("$dir/$file", $contents);
}
?>