<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_radicalmart_search
 *
 * @copyright   (C) 2025-2026 Dharma Design
 * @license     GNU General Public License version 2 or later
 */

namespace Joomla\Module\RadicalMartSearch\Site\Dispatcher;

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Factory;
use Joomla\Module\RadicalMartSearch\Site\Helper\RadicalMartSearchHelper;

class Dispatcher extends AbstractModuleDispatcher
{
	/**
	 * Returns the layout data.
	 *
	 * @throws \Exception
	 *
	 * @return  array Module layout data.
	 *
	 * @since  1.0.0
	 */
	protected function getLayoutData(): array
	{
		$data = parent::getLayoutData();

		$app = Factory::getApplication();

		// Load RadicalMart language
		$app->getLanguage()->load('com_radicalmart');

		// Get catalog URL from menu_item parameter
		$data['catalog_url'] = RadicalMartSearchHelper::getCatalogUrl($data['params']);

		return $data;
	}
}

