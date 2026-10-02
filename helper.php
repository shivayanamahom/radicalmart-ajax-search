<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_radicalmart_search
 *
 * @copyright   (C) 2025
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\Module\RadicalMartSearch\Site\Helper\RadicalMartSearchHelper;

class ModRadicalmartSearchHelper
{
	public static function getAjax()
	{
		return RadicalMartSearchHelper::getAjax();
	}
}

