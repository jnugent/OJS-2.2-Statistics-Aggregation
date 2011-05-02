<?php

/**
 * @file StatisticsAggregationPlugin.inc.php
 *
 * Copyright (c) 2003-2010 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class StatisticsAggregationPlugin
 * @ingroup plugins_generic_statisticsAggregation
 *
 * @brief Statistics Aggregation for Synergies/SUSHI plugin class
 */

// $Id$


import('classes.plugins.GenericPlugin');

class StatisticsAggregationPlugin extends GenericPlugin {
	/**
	 * Called as a plugin is registered to the registry
	 * @param $category String Name of category plugin was registered to
	 * @return boolean True iff plugin initialized successfully; if false,
	 * 	the plugin will not be registered.
	 */
	function register($category, $path) {
		if (parent::register($category, $path)) {
			$this->addLocaleData();
			HookRegistry::register('TemplateManager::display', array(&$this, 'callbackInsertSA'));
			return true;
		} else {
			return false;
		}
	}

	function getName() {
		return 'StatisticsAggregationPlugin';
	}

	function getDisplayName() {
		return Locale::translate('plugins.generic.statisticsAggregation.displayName');
	}

	function getDescription() {
		return Locale::translate('plugins.generic.statisticsAggregation.description');
	}

	/**
	 * Set the page's breadcrumbs, given the plugin's tree of items
	 * to append.
	 * @param $subclass boolean
	 */
	function setBreadcrumbs($isSubclass = false) {
		$templateMgr =& TemplateManager::getManager();
		$pageCrumbs = array(
			array(
				Request::url(null, 'user'),
				'navigation.user'
			),
			array(
				Request::url(null, 'manager'),
				'user.role.manager'
			)
		);
		if ($isSubclass) $pageCrumbs[] = array(
			Request::url(null, 'manager', 'plugins'),
			'manager.plugins'
		);

		$templateMgr->assign('pageHierarchy', $pageCrumbs);
	}

	/**
	 * Display verbs for the management interface.
	 */
	function getManagementVerbs() {
		$verbs = array();
		if ($this->getEnabled()) {
			$verbs[] = array('disable', Locale::translate('manager.plugins.disable'));
			$verbs[] = array('settings', Locale::translate('plugins.generic.statisticsAggregation.manager.settings'));
			$verbs[] = array('viewstats', Locale::translate('plugins.generic.statisticsAggregation.manager.viewstats') );
		} else {
			$verbs[] = array('enable', Locale::translate('manager.plugins.enable'));
		}

		return$verbs;
	}

	/**
	 * build the statistics
	 */
	function callbackInsertSA($hookName, $params) {

		if ($this->getEnabled()) {
			$templateMgr =& $params[0];
			$template =& $params[1];
			$currentJournal = $templateMgr->get_template_vars('currentJournal');
			if (!empty($currentJournal)) {
				$journal =& Request::getJournal();

				if (!empty($journal) && ! Request::isBot()) {

					$article = $templateMgr->get_template_vars('article');
					$galley = $templateMgr->get_template_vars('galley');
					$statisticsAggregationSiteId = $this->getSetting($journal->getJournalId(), 'statisticsAggregationSiteId');

					switch ($template) {
						case 'article/article.tpl':
						case 'article/interstitial.tpl':
						case 'article/pdfInterstitial.tpl':
						// Log the request as an article view.
							$statsArray = $this->buildStatsArray($galley, $article);
							$this->sendData($statsArray, $statisticsAggregationSiteId);
						break;
						default:
							$statsArray = $this->buildStatsArray(null, null); // regular page view, no galley or article
							if ($statsArray['rp'] != 'manager' && $template != 'rt/rt.tpl') { // do not accumulate stats for journal management pages or research toolbar.
								$this->sendData($statsArray, $statisticsAggregationSiteId);
							}
						break;
					}
				}
			}
		}
		return false;
	}

	/**
	 * @brief encodes the statsArray into JSON and sends it up to the aggregation server through a Socket object.
	 * @param Array $statsArray the array containing information about the page requested.
	 * @param String $statisticsAggregationSiteId the Hash Code for this Journal.
	 */
	function sendData($statsArray, $statisticsAggregationSiteId) {

		$this->import('JSONEncoder');
		$encoder = new JSONEncoder();
		$encoder->setAdditionalAttributes($statsArray);
		$jsonString = $encoder->getString();
		$this->import('StatisticsSocket');
		$statisticsSocket = new StatisticsSocket();
		$statisticsSocket->send($statisticsAggregationSiteId, $jsonString);
	}

	/**
	 * @brief examines the context of the current request and assembles an array containing the relevant bits of info we want to collect
	 * @param Object $galley the galley object (HTML or PDF, usually) for this page view, null if a regular non-article page.
	 * @param Article $article the article object representing the current article being viewed, null if a regular non-article page.
	 * @return Array $statsArray the array of our information.
	 */
	function buildStatsArray($galley, $article) {

		$statsArray = array();

		if ($galley) {
			if ($galley->isPdfGalley()) {
				$statsArray['mt'] = 'PDF';
			} else if ($galley->isHTMLGalley()) {
				$statsArray['mt'] = 'HTML';
			}
		} else if ($article) {
			$statsArray['mt'] = 'ABSTRACT';
		} else {
			$statsArray['mt'] = '';
		}

		$statsArray['ip'] =& Request::getRemoteAddr();
		$statsArray['rp'] =& Request::getRequestedPage();
		$statsArray['ua'] = $_SERVER["HTTP_USER_AGENT"];
		$statsArray['ts'] = date('d/M/Y:H:i:s O', time());
		if ($article) {
			$statsArray['title'] = $article->getArticleTitle();
			$statsArray['authors'] = $article->getAuthorString();
		} else {
			$statsArray['title'] = '';
		}
		$statsArray['pr'] =& Request::getProtocol();
		$statsArray['host'] =& Request::getServerHost();

		if (isset($_SERVER['HTTP_REFERER']) && $this->isRemoteReferer($statsArray['pr'] . '://' . $statsArray['host'], $_SERVER['HTTP_REFERER'])) {
			$statsArray['ref'] = $_SERVER['HTTP_REFERER'];
		} else {
			$statsArray['ref'] = '';
		}
		$statsArray['uri'] =& Request::getRequestPath();
		return $statsArray;
	}

	/**
	 * @brief determines if a referring document is coming from an off-site location.
	 * @param $docHost the base host of this request (e.g., http://your.journals.site).
	 * @param $referer the full referring document, if there was one.
	 * @return boolean true if the referring document has a different base domain.
	 */
	function isRemoteReferer($docHost, $referer) {
		if (!preg_match("{^" . quotemeta($docHost) . "}", $referer)) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Determine whether or not this plugin is enabled.
	 */
	function getEnabled() {
			$journal = &Request::getJournal();
			if (!$journal) return false;
			return $this->getSetting($journal->getJournalId(), 'enabled');
	}

	/**
	 * Set the enabled/disabled state of this plugin
	 */
	function setEnabled($enabled) {
			$journal = &Request::getJournal();
			if ($journal) {
					$this->updateSetting($journal->getJournalId(), 'enabled', $enabled ? true : false);
					return true;
			}
			return false;
	}

 	/*
 	 * Execute a management verb on this plugin
 	 * @param $verb string
 	 * @param $args array
 	 * @return boolean
 	 */
	function manage($verb, $args) {

		$journal =& Request::getJournal();

		switch ($verb) {

			case 'enable':
				$this->setEnabled(true);
				return false;
			 	break;
			case 'disable':
				$this->setEnabled(false);
				return false;
				break;
			case 'getNewHash':
				$emailAddress = Request::getUserVar('email');
				$primaryLocale =& $journal->getPrimaryLocale();
				$journalTitle =& $journal->getTitle($primaryLocale);

				if ($emailAddress != '')  {
					$journalTitle = preg_replace("{/}", " ", $journalTitle); // FIXME this should probably use a JSON call instead.
					$jsonResult = file_get_contents('http://warhammer.hil.unb.ca/index.php/getNewHash/0/' . urlencode($emailAddress) . '/' . urlencode($journalTitle) . '/' . urlencode($primaryLocale));
					echo $jsonResult;
					return true;
				} else {
					return false;
				}
			case 'settings':
				$templateMgr =& TemplateManager::getManager();
				$templateMgr->register_function('plugin_url', array(&$this, 'smartyPluginUrl'));

				$this->import('StatisticsAggregationSettingsForm');
				$form = new StatisticsAggregationSettingsForm($this, $journal->getJournalId());
				if (Request::getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						Request::redirect(null, 'manager', 'plugin');
						return false;
					} else {
						$this->setBreadCrumbs(true);
						$form->display();
					}
				} else {
					$this->setBreadCrumbs(true);
					$form->initData();
					$form->display();
				}
				return true;
			case 'viewstats':
				$statisticsAggregationSiteId = $this->getSetting($journal->getJournalId(), 'statisticsAggregationSiteId');
				if ($statisticsAggregationSiteId != '') {
					Request::redirectUrl('http://warhammer.hil.unb.ca/stats/' . $statisticsAggregationSiteId);
				}
				return true;
			default:
				// Unknown management verb
				assert(false);
				return false;
		}
	}
}
?>