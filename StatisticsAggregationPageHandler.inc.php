<?php

/**
 * @file StatisticsAggregationPageHandler.inc.php
 *
 * Copyright (c) 2003-2010 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @package plugins.generic.statisticsAggregator
 * @class StatisticsAggregationPageHandler
 *
 */

class StatisticsAggregationPageHandler extends Handler {

	function index($args) {
		return true;
	}

	function viewstats($args) {

		$journal =& Request::getJournal();
		$journalId = $journal->getJournalId();
		$statAggrPlugin =& PluginRegistry::getPlugin('generic', 'StatisticsAggregationPlugin');
		$statisticsAggregationSiteId =& $statAggrPlugin->getSetting($journalId, 'statisticsAggregationSiteId');
		if ($statisticsAggregationSiteId != '') {
			Request::redirectUrl('http://warhammer.hil.unb.ca/stats/' . $statisticsAggregationSiteId . '/landing.php');
		}
	}

	function lookup($args) {

		$journal =& Request::getJournal();
		$journalId = $journal->getJournalId();

		$subscriptionDao =& DAORegistry::getDAO('SubscriptionDAO');
		$journalStatisticsDao =& DAORegistry::getDAO('JournalStatisticsDAO');

		$subscriptionStats = $journalStatisticsDao->getSubscriptionStatistics($journalId);
		$registeredUsers = $journalStatisticsDao->getUserStatistics($journalId);

		$totalReaders = 0;

		if (isset($registeredUsers['reader'])) {
			$totalReaders = $registeredUsers['reader'];
		}

		$ipXML = Request::getUserVar('ipXML');

		if ($ipXML != '') {

			// build an XML doc based on the IP document posted from the aggregation server
			$xmlDoc = new DOMDocument();
			$xmlDoc->loadXML($ipXML);
			if ($xmlDoc) {

				// grab the <h> node which contains the <v> visitor nodes
				$hostNodeList = $xmlDoc->getElementsByTagName('h');

				if ($hostNodeList->length == 1) { // there should just be one top level host node.
					$node =& $hostNodeList->item(0);
					if ($node->hasAttributes()) {

						// the <h> node has a 'key' attribute containing the sha1 security hash sent along
						$key =& $node->attributes->item(0)->value;

						// key is a security code we included in the XML document when we sent it
						if (preg_match("/^[\w\d]{40}$/", $key)) {

							// load our plugin to get settings
							$statAggrPlugin =& PluginRegistry::getPlugin('generic', 'StatisticsAggregationPlugin');
							$storedHashCode =& $statAggrPlugin->getSetting($journalId, 'statisticsAggregationSiteId');
							$storedEmailAddress =& $statAggrPlugin->getSetting($journalId, 'statisticsAggregationSiteEmail');

							// this was a valid submission? the keys match?
							if (sha1($storedHashCode . $storedEmailAddress) == $key) {

								// grab <v> nodes.
								$visitorNodes = $xmlDoc->getElementsByTagName('v');
								$visitorNodeCount = $visitorNodes->length;

								for ($i = 0 ; $i < $visitorNodeCount ; $i ++) {

									$visitorNode =& $visitorNodes->item($i);
									$hostToSearch =& $visitorNode->attributes->item(0)->value; // it's either a domain or an IP address

									$subscriptionId = false;
									$subscriptionName = '';
									$domain = $ipAddress = null;
									if (preg_match("{^\d+\.\d+\.\d+\.\d+$}", $hostToSearch)) { // it's an IP?
										$ipAddress = $hostToSearch;
										$domain = null;
									} else {
										$domain = $hostToSearch;
										$ipAddress = null;
									}

									$subscriptionId = $subscriptionDao->isValidSubscription($domain, $ipAddress, null, $journalId);

									if ($subscriptionId) {
										$subscription =& $subscriptionDao->getSubscription($subscriptionId);
										$userDao =& DAORegistry::getDAO('UserDAO');
										$subScriptionUser =& $userDao->getUser($subscription->getUserId());
										$subscriptionName = $subScriptionUser->getFullName();
									}

									// whether we found a subscription or not, set the 'sub' attribute on the <v> node so we can send it back.
									$visitorNode->setAttribute('sub', $subscriptionName);

								}
							} else { // error out -- incorrect security key
								return null;
							}
						}
					}
				}

				// build the new document
				$rootNode = $xmlDoc->getElementsByTagName('visitorDocument')->item(0);

				$userInformationElement = $xmlDoc->createElement('userInfo');
				$readerTotalElement = $xmlDoc->createElement('readers');
				$readerTotalText = $xmlDoc->createTextNode($totalReaders);
				$readerTotalElement->appendChild($readerTotalText);
				$userInformationElement->appendChild($readerTotalElement);

				$subscriptionsElement = $xmlDoc->createElement('subscriptions');

				foreach ($subscriptionStats as $subscriptionType) {

					$subscriptionElement = $xmlDoc->createElement('sub');
					$subscriptionElement->setAttribute('name', $subscriptionType['name']);
					$subscriptionElement->setAttribute('count', $subscriptionType['count']);
					$subscriptionsElement->appendChild($subscriptionElement);
				}

				$userInformationElement->appendChild($subscriptionsElement);

				// import our new user info into the original document, append it, and send it back.
				$userInformationElement = $xmlDoc->importNode($userInformationElement, true);
				$rootNode->appendChild($userInformationElement);
				print $xmlDoc->saveXML(); // this is the respone to the CURL request from the aggregation server.

				exit(0);
			}
		} else {
			error_log("No XML document retrieved.  Exiting.");
			exit(0);
		}
	}
}

?>
