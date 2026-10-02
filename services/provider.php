<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_radicalmart_search
 *
 * @copyright   (C) 2025
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\Service\Provider\HelperFactory;
use Joomla\CMS\Extension\Service\Provider\Module;
use Joomla\CMS\Extension\Service\Provider\ModuleDispatcherFactory;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class implements ServiceProviderInterface {

	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @since   1.0.0
	 */
	public function register(Container $container)
	{
		// Register services
		$container->registerServiceProvider(new ModuleDispatcherFactory('\\Joomla\\Module\\RadicalMartSearch'));
		$container->registerServiceProvider(new HelperFactory('\\Joomla\\Module\\RadicalMartSearch\\Site\\Helper'));
		$container->registerServiceProvider(new Module());
	}
};

